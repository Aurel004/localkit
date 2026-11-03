<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DevSoundGetResource extends PetkitHttpResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof Device) {
            return [];
        }

        $sound = $this->resource->configuration['feed_sound'] ?? null;

        if (!$sound || empty($sound['digest']) || empty($sound['filename'])) {
            $disk = Storage::disk('public');
            $files = $disk->files('sounds');
            $found = null;
            $foundId = (int) ($this->resource->configuration['settings']['selectedSound'] ?? 1);
            if ($foundId <= 0) $foundId = 1;
            foreach ($files as $file) {
                if (preg_match('/^sounds\/feed_' . $this->resource->id . '_(\d+)\.aac$/', $file, $m)) {
                    $found = $file;
                    $foundId = (int) $m[1];
                    break;
                }
            }
            if ($found && $disk->exists($found)) {
                $fullPath = $disk->path($found);
                $sound = [
                    'id' => $foundId,
                    'digest' => md5_file($fullPath),
                    'size' => filesize($fullPath),
                    'duration' => 1,
                    'filename' => "feed_{$foundId}.aac",
                    'path' => $found,
                    'gmtCreate' => now()->toDateTimeString(),
                ];
            }
        }

        if (!$sound || empty($sound['digest']) || empty($sound['filename'])) {
            return [];
        }

        $endpoint = rtrim((string) config('localkit.storage.endpoint'), '/');
        $endpoint = preg_replace('/^https:\/\//', 'http://', $endpoint);

        return [
            [
                'id' => (int) ($sound['id'] ?? 1),
                'digest' => (string) $sound['digest'],
                'size' => (int) $sound['size'],
                'duration' => (int) ($sound['duration'] ?? 1),
                'url' => $endpoint . '/sound/feed/' . $this->resource->id . '/' . $sound['filename'],
                'gmtCreate' => $sound['gmtCreate'] ?? now()->toDateTimeString(),
            ],
        ];
    }

}
