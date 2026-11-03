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

        if (!$sound || empty($sound['path'])) {
            abort(404, 'No feed sound configured for this device.');
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($sound['path'])) {
            abort(404, 'Feed sound file not found on disk.');
        }

        $fullPath = $disk->path($sound['path']);

        return response()->file($fullPath, [
            'Content-Type' => 'audio/aac',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
