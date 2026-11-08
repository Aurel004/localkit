<?php

namespace Tests\Unit;

use App\Http\Resources\MQTT\SuccessResource;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use stdClass;
use Tests\TestCase;

class MqttAckAndDispenseDedupTest extends TestCase
{
    public function test_success_resource_derives_method_and_strict_version(): void
    {
        $message = (object) ['id' => '1234'];
        $resource = SuccessResource::make($message)->forTopic('/sys/localkit/d_d4sh_123/thing/event/feed_over/post');

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame(200, $array['code']);
        $this->assertSame('1234', $array['id']);
        $this->assertSame('success', $array['message']);
        $this->assertSame('thing.event.feed_over.post', $array['method']);
        $this->assertSame('1.0', $array['version']);
    }

    public function test_success_resource_handles_explicit_method_and_null_id(): void
    {
        $message = new stdClass();
        $resource = SuccessResource::make($message, 'thing.service.test')->forTopic('/sys/test');

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('', $array['id']);
        $this->assertSame('thing.service.test', $array['method']);
        $this->assertSame('1.0', $array['version']);
    }

    public function test_dispense_event_cache_deduplication(): void
    {
        $deviceId = 999;
        $eventId = 'event_12345';
        $cacheKey = "dispense_event_{$deviceId}_{$eventId}";

        Cache::forget($cacheKey);

        $firstAttempt = Cache::add($cacheKey, true, now()->addHours(24));
        $this->assertTrue($firstAttempt, 'First dispense event should be accepted');

        $secondAttempt = Cache::add($cacheKey, true, now()->addHours(24));
        $this->assertFalse($secondAttempt, 'Duplicate dispense event within 24h must be rejected');

        Cache::forget($cacheKey);
    }
}
