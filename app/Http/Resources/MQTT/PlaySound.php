<?php

namespace App\Http\Resources\MQTT;

use AllowDynamicProperties;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[AllowDynamicProperties] class PlaySound extends JsonResource
{
    public function setSoundId(int $soundId): self
    {
        $this->soundId = $soundId;
        return $this;
    }

    public function toArray(Request $request)
    {
        return [
            "service_id" => 'play_sound',
            "method" => 'thing.service.play_sound',
            'id' => (string) time(),
            "params" => [
                'soundId' => $this->soundId ?? 1,
            ],
            "version" => "1.0.0",
        ];
    }
}
