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

    public function test_read_returns_empty_when_no_messages(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $messages = $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);

        $this->assertSame([], $messages);
        $this->assertSame([['stream-bus:events:outbound', 'g1']], $connection->groupsCreated);
    }

    public function test_ack_is_noop_for_lists(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $ackCount = $bus->ack('events:outbound', '1-1', ['driver' => 'lists']);

        $this->assertSame(0, $ackCount);
    }

    public function test_prefix_is_applied_to_keys(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'app1:bus:',
        ]);

        $bus->publish('events:outbound', ['foo' => 'bar']);

        $this->assertArrayHasKey('app1:bus:events:outbound', $connection->lists);
    }

    public function test_driver_can_be_overridden_per_call(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $bus->publish('events:outbound', ['foo' => 'bar'], ['driver' => 'lists']);

        $this->assertArrayHasKey('stream-bus:events:outbound', $connection->lists);
    }

    public function test_should_process_defaults_to_true(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $this->assertTrue($bus->shouldProcess('events:outbound', '1-1'));
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
        $this->assertArrayHasKey('stream-bus:events:outbound:dedupe:1-1', $connection->kv);
    }
}
