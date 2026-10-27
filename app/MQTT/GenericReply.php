<?php

namespace App\MQTT;

use App\Helpers\MQTTTopic;
use App\Http\Resources\MQTT\SuccessResource;

class GenericReply
{
    public static function reply(string $topic, mixed $message): AnswerDTO
    {
        $method = $message?->method ?? null;
        if (empty($method) && str_contains($topic, '/thing/')) {
            $method = 'thing.' . MQTTTopic::topic2Method($topic);
        }

        return new AnswerDTO(
            topic: sprintf('%s_reply', $topic),
            message: SuccessResource::make($message, $method)
        );
    }
}
