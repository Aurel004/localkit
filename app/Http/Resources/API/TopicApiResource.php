<?php

namespace App\Http\Resources\API;

use App\Models\Device;
use App\Petkit\DeviceDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TopicApiResource extends JsonResource
{
    public static $wrap = 'data';


    /**
     * The broker only forwards to a device the topics listed here.
     *
     * Besides the services the definition subscribes to, this has to include
     * the post_reply of every event the definition handles: devices send their
     * events with sys.ack=1 and Localkit acks each one on <event>/post_reply.
     * If that reply topic is missing here, the ack never reaches the device,
     * which then keeps re-sending the event - a feeder holds its feed_over in
     * a queue and retransmits the oldest one forever, so newer feed records
     * are never delivered.
     */
    public function toArray(Request $request)
    {
        /** @var DeviceDefinition $definition */
        $definition = $this->resource->definition();

        $topics = $definition->subscribedTopics();

        foreach (array_keys($definition->stateTopics()) as $topic) {
            if (str_ends_with($topic, '/post')) {
                $topics[] = $topic . '_reply';
            }
        }

        return [
            'serialNumber' => $this->serial_number,
            'topics' => array_values(array_unique($topics)),
        ];
    }

}
