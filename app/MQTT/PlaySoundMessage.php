<?php

namespace App\MQTT;

use App\Http\Resources\MQTT\PlaySound;
use App\Models\Device;

class PlaySoundMessage
{
    public static function send(Device $device, int $soundId = 1): AnswerDTO
    {
        return new AnswerDTO(
            topic: sprintf('/sys/%s/%s/thing/service/play_sound', $device->productKey(), $device->deviceName()),
            message: (PlaySound::make($device))->setSoundId($soundId),
        );
    }
}
