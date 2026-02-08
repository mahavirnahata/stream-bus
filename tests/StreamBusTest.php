<?php

namespace MahavirNahata\StreamBus\Tests;

use MahavirNahata\StreamBus\StreamBus;
use PHPUnit\Framework\TestCase;

class StreamBusTest extends TestCase
{
    public function test_publish_and_read_streams(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $id = $bus->publish('events:outbound', ['foo' => 'bar']);

        $messages = $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);

        $this->assertNotEmpty($id);
        $this->assertCount(1, $messages);
        $this->assertSame('bar', $messages[0]['message']['payload']['foo']);
    }

    public function test_ack_removes_stream_message(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $id = $bus->publish('events:outbound', ['foo' => 'bar']);

        $messages = $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);
        $this->assertCount(1, $messages);

        $ackCount = $bus->ack('events:outbound', $messages[0]['id'], ['group' => 'g1']);

        $this->assertSame(1, $ackCount);
        $this->assertEmpty($connection->streams['stream-bus:events:outbound'] ?? []);
    }

    public function test_publish_and_read_lists(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $id = $bus->publish('events:outbound', ['foo' => 'bar']);
        $messages = $bus->read('events:outbound', ['driver' => 'lists', 'block' => 1]);

        $this->assertNotEmpty($id);
        $this->assertCount(1, $messages);
        $this->assertSame('bar', $messages[0]['message']['payload']['foo']);
    }

    public function test_effectively_once_dedupe(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
            'delivery' => 'effectively-once',
            'dedupe_ttl' => 3600,
        ]);

        $this->assertTrue($bus->shouldProcess('events:outbound', '1-1'));
        $this->assertFalse($bus->shouldProcess('events:outbound', '1-1'));
    }
}
