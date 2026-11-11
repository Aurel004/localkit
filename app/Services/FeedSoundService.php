<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class FeedSoundService
{
    /**
     * Target audio specifications required by PetKit feeder hardware:
     * - Ingenic T31 / MIPS OSS audio decoder
     * - Format: MPEG ADTS AAC-LC
     * - Channels: 1 (mono)
     * - Sample Rate: 16000 Hz
     * - Bitrate: 32 kbps
     */
    public const TARGET_SAMPLE_RATE = 16000;
    public const TARGET_CHANNELS = 1;
    public const TARGET_BITRATE = '32k';

    /**
     * Process an uploaded audio file, convert it to the feeder's exact AAC specification,
     * store it persistently, and return metadata for /dev_sound_get and device settings.
     *
     * @return array{id: int, digest: string, size: int, duration: int, filename: string, path: string, gmtCreate: string}|null
     */
    public static function processUpload(Device $device, string $uploadedRelativePath): ?array
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($uploadedRelativePath)) {
            Log::warning('FeedSoundService: uploaded file does not exist', ['path' => $uploadedRelativePath]);
            return null;
        }

        $inputFullPath = $disk->path($uploadedRelativePath);
        $disk->makeDirectory('sounds');

        // Increment or alternate sound ID (1..99) so the device detects a configuration change
        $currentId = (int) ($device->configuration['feed_sound']['id'] ?? 0);
        $nextId = $currentId >= 99 ? 1 : ($currentId + 1);

        $filename = "feed_{$nextId}.aac";
        $outputRelativePath = "sounds/feed_{$device->id}_{$nextId}.aac";
        $outputFullPath = $disk->path($outputRelativePath);

        // Delete any previous sound files for this device to prevent clutter
        self::cleanupOldFiles($device, $nextId);

        // Convert using ffmpeg
        $converted = self::convertToFeederAac($inputFullPath, $outputFullPath);

        if (!$converted || !file_exists($outputFullPath)) {
            Log::error('FeedSoundService: audio conversion failed', [
                'input' => $inputFullPath,
                'output' => $outputFullPath,
            ]);
            return null;
        }

        $digest = md5_file($outputFullPath);
        $size = filesize($outputFullPath);
        $duration = self::detectDuration($outputFullPath);

        return [
            'id' => $nextId,
            'digest' => $digest,
            'size' => $size,
            'duration' => max(1, (int) round($duration)),
            'filename' => $filename,
            'path' => $outputRelativePath,
            'gmtCreate' => now()->toDateTimeString(),
        ];
    }

    /**
     * Convert any supported audio file to AAC-LC 16kHz mono using FFmpeg.
     */
    public static function convertToFeederAac(string $inputPath, string $outputPath): bool
    {
        $ffmpeg = env('FFMPEG_BINARY', config('camera.ffmpeg', 'ffmpeg'));

        $cmd = [
            $ffmpeg,
            '-y',
            '-i', $inputPath,
            '-vn',
            '-c:a', 'aac',
            '-b:a', self::TARGET_BITRATE,
            '-ar', (string) self::TARGET_SAMPLE_RATE,
            '-ac', (string) self::TARGET_CHANNELS,
            $outputPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('FeedSoundService ffmpeg error: ' . $process->getErrorOutput());
            return false;
        }

        return true;
    }

    /**
     * Detect duration in seconds using ffprobe or fallback to 1.
     */
    public static function detectDuration(string $filePath): float
    {
        $ffprobe = env('FFPROBE_BINARY', 'ffprobe');

        $cmd = [
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $filePath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(5);
        $process->run();

        if ($process->isSuccessful()) {
            $val = trim($process->getOutput());
            if (is_numeric($val) && (float) $val > 0) {
                return (float) $val;
            }
        }

        return 1.0;
    }

    /**
     * Rebuild the feed sound metadata from the converted file kept in persistent storage
     * (storage/app/public/sounds/feed_{device}_{id}.aac).
     *
     * Used when the device configuration lost its `feed_sound` entry (e.g. the JSON
     * configuration was rewritten by code unaware of these keys) while the audio file
     * itself still exists. Files are only deleted by an explicit removal in the UI
     * (cleanupOldFiles), so an existing file always reflects the user's last choice.
     *
     * @return array{id: int, digest: string, size: int, duration: int, filename: string, path: string, gmtCreate: string}|null
     */
    public static function recoverFromDisk(Device $device): ?array
    {
        static $cache = [];

        if (!$device->exists) {
            return null;
        }

        $disk = Storage::disk('public');
        $found = null;
        $foundId = 0;
        $foundTime = -1;

        foreach ($disk->files('sounds') as $file) {
            if (preg_match('/^sounds\/feed_' . $device->id . '_(\d+)\.aac$/', $file, $m)) {
                $time = $disk->lastModified($file);
                if ($time > $foundTime) {
                    $found = $file;
                    $foundId = (int) $m[1];
                    $foundTime = $time;
                }
            }
        }

        if ($found === null) {
            return null;
        }

        $fullPath = $disk->path($found);
        $cacheKey = $found . '|' . $foundTime . '|' . filesize($fullPath);

        // fromDevice() runs on every MQTT message: avoid re-running ffprobe each time
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = [
                'id' => $foundId,
                'digest' => md5_file($fullPath),
                'size' => filesize($fullPath),
                'duration' => max(1, (int) round(self::detectDuration($fullPath))),
                'filename' => "feed_{$foundId}.aac",
                'path' => $found,
                'gmtCreate' => date('Y-m-d H:i:s', $foundTime),
            ];
        }

        return $cache[$cacheKey];
    }

    /**
     * Recover the feed sound from storage (see recoverFromDisk) and persist it back into
     * the device configuration. Returns the active sound metadata, or null if none.
     */
    public static function restore(Device $device): ?array
    {
        $current = $device->configuration['feed_sound'] ?? null;
        if (!empty($current['digest']) && !empty($current['filename'])) {
            return $current;
        }

        $sound = self::recoverFromDisk($device);
        if ($sound === null) {
            return null;
        }

        $config = $device->configuration ?? [];
        $config['feed_sound'] = $sound;
        $config['feed_sound_file'] = $config['feed_sound_file'] ?? $sound['path'];
        if ((int) ($config['settings']['selectedSound'] ?? -1) <= 0) {
            $config['settings']['selectedSound'] = $sound['id'];
        }

        $device->configuration = $config;
        $device->saveQuietly();

        Log::info('FeedSoundService: feed sound restored from storage', [
            'device' => $device->id,
            'path' => $sound['path'],
        ]);

        return $sound;
    }

    /**
     * Remove old generated sound files for this device.
     */
    public static function cleanupOldFiles(Device $device, ?int $exceptId = null): void
    {
        $disk = Storage::disk('public');
        $files = $disk->files('sounds');

        foreach ($files as $file) {
            if (preg_match('/^sounds\/feed_' . $device->id . '_(\d+)\.aac$/', $file, $m)) {
                $fileId = (int) $m[1];
                if ($exceptId === null || $fileId !== $exceptId) {
                    $disk->delete($file);
                }
            }
        }
    }
}
