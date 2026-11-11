<?php

namespace App\Http\Resources;

use App\Models\Device;
use App\Services\FeedSoundService;
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
            // Configuration lost its metadata: recover it from storage and persist it
            $sound = FeedSoundService::restore($this->resource);
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
