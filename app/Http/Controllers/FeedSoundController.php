<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeedSoundController extends Controller
{
    /**
     * Serves custom feed sound AAC files directly to the device (or browser preview).
     * The feeder firmware fetches this via `wget <url> -P /tmp` over unauthenticated HTTP.
     */
    public function __invoke(Device $device, string $filename): BinaryFileResponse
    {
        $sound = $device->configuration['feed_sound'] ?? null;
        $path = $sound['path'] ?? ('sounds/' . $filename);

        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            // Fallback: check matching sound file in sounds directory
            $matched = null;
            $files = $disk->files('sounds');
            foreach ($files as $file) {
                if (str_ends_with($file, '.aac') && str_contains($file, "feed_{$device->id}_")) {
                    $matched = $file;
                    break;
                }
            }
            if ($matched) {
                $path = $matched;
            } else {
                abort(404, 'Feed sound file not found on disk.');
            }
        }

        $fullPath = $disk->path($path);

        return response()->file($fullPath, [
            'Content-Type' => 'audio/aac',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
