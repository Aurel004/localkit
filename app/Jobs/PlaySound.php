<?php

namespace App\Jobs;

use App\Models\Device;
use App\MQTT\PlaySoundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PhpMqtt\Client\Facades\MQTT;

class PlaySound implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Device $device, protected int $soundId = 1)
    {
    }

    public function handle(): void
    {
        $message = PlaySoundMessage::send($this->device, $this->soundId);

        $connection = MQTT::connection('publisher');
        $connection->publish($message->getTopic(), $message->getMessage());
        $connection->disconnect();
    }
}
