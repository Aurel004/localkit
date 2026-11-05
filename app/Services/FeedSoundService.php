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
