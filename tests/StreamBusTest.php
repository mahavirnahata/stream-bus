<?php

namespace MahavirNahata\StreamBus\Tests;

use MahavirNahata\StreamBus\StreamBus;
use PHPUnit\Framework\TestCase;

class StreamBusTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Streams driver
    // -------------------------------------------------------------------------

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

        $bus->publish('events:outbound', ['foo' => 'bar']);
        $messages = $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);
        $this->assertCount(1, $messages);

        $ackCount = $bus->ack('events:outbound', $messages[0]['id'], ['group' => 'g1']);

        $this->assertSame(1, $ackCount);
        $this->assertEmpty($connection->streams['stream-bus:events:outbound'] ?? []);
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

    public function test_stream_maxlen_trims_old_messages(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
            'maxlen' => 2,
        ]);

        $bus->publish('events:outbound', ['n' => 1]);
        $bus->publish('events:outbound', ['n' => 2]);
        $bus->publish('events:outbound', ['n' => 3]);

        $this->assertCount(2, $connection->streams['stream-bus:events:outbound']);
    }

    public function test_reclaim_returns_pending_stream_messages(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $bus->publish('events:outbound', ['foo' => 'reclaimed']);
        $reclaimed = $bus->reclaim('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);

        $this->assertCount(1, $reclaimed);
        $this->assertSame('reclaimed', $reclaimed[0]['message']['payload']['foo']);
    }

    public function test_reclaim_is_noop_for_lists(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), ['driver' => 'lists']);

        $result = $bus->reclaim('events:outbound');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // ensureGroupExists — BUSYGROUP silencing
    // -------------------------------------------------------------------------

    public function test_busygroup_exception_is_silenced_and_read_continues(): void
    {
        $connection = new class extends FakeRedisConnection {
            public function xgroup(...$args): bool
            {
                throw new \RuntimeException('BUSYGROUP Consumer Group name already exists');
            }
        };

        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        // Must not throw; group-already-exists is a normal condition
        $messages = $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);
        $this->assertSame([], $messages);
    }

    public function test_non_busygroup_xgroup_exception_propagates(): void
    {
        $connection = new class extends FakeRedisConnection {
            public function xgroup(...$args): bool
            {
                throw new \RuntimeException('WRONGTYPE Operation against a key holding the wrong kind of value');
            }
        };

        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);
    }

    // -------------------------------------------------------------------------
    // Lists driver
    // -------------------------------------------------------------------------

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

    public function test_lists_driver_preserves_fifo_order(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $bus->publish('q', ['n' => 1]);
        $bus->publish('q', ['n' => 2]);
        $bus->publish('q', ['n' => 3]);

        $m1 = $bus->read('q', ['block' => 0]);
        $m2 = $bus->read('q', ['block' => 0]);
        $m3 = $bus->read('q', ['block' => 0]);

        $this->assertSame(1, $m1[0]['message']['payload']['n']);
        $this->assertSame(2, $m2[0]['message']['payload']['n']);
        $this->assertSame(3, $m3[0]['message']['payload']['n']);
    }

    public function test_lists_read_returns_empty_when_no_messages(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $messages = $bus->read('events:outbound', ['block' => 0]);

        $this->assertSame([], $messages);
    }

    // -------------------------------------------------------------------------
    // Retry and dead-letter
    // -------------------------------------------------------------------------

    public function test_retry_requeues_message_for_lists(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $bus->publish('events:outbound', ['foo' => 'bar']);
        $messages = $bus->read('events:outbound', ['block' => 0]);
        $this->assertCount(1, $messages);

        $bus->retry('events:outbound', $messages[0]['message']);

        $retried = $bus->read('events:outbound', ['block' => 0]);
        $this->assertCount(1, $retried);
        $this->assertSame(1, $retried[0]['message']['_attempt']);
    }

    public function test_retry_is_noop_for_streams(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), ['driver' => 'streams']);

        $bus->retry('events:outbound', ['id' => 'abc', 'payload' => []]);

        $this->assertEmpty($connection->lists);
    }

    public function test_dead_letter_publishes_to_dlq_topic(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $bus->deadLetter('events:outbound', ['payload' => ['foo' => 'failed']]);

        $this->assertArrayHasKey('stream-bus:events:outbound:dead-letter', $connection->lists);
    }

    public function test_dead_letter_respects_custom_topic(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
            'dead_letter_topic' => 'my:dlq',
        ]);

        $bus->deadLetter('events:outbound', ['payload' => ['foo' => 'failed']]);

        $this->assertArrayHasKey('stream-bus:my:dlq', $connection->lists);
    }

    // -------------------------------------------------------------------------
    // Attempt tracking (atomic via Lua eval)
    // -------------------------------------------------------------------------

    public function test_increment_attempts_tracks_delivery_count(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $this->assertSame(1, $bus->incrementAttempts('events:outbound', 'msg-1'));
        $this->assertSame(2, $bus->incrementAttempts('events:outbound', 'msg-1'));
        $this->assertSame(3, $bus->incrementAttempts('events:outbound', 'msg-1'));
        $this->assertSame(1, $bus->incrementAttempts('events:outbound', 'msg-2'));
    }

    // -------------------------------------------------------------------------
    // Deduplication
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Redis Cluster — hash-tag key format
    // -------------------------------------------------------------------------

    public function test_cluster_mode_wraps_topic_in_hash_tags(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
            'cluster' => true,
        ]);

        $bus->publish('events:outbound', ['foo' => 'bar']);

        $this->assertArrayHasKey('stream-bus:{events:outbound}', $connection->lists);
    }

    public function test_cluster_mode_dedupe_key_shares_hash_tag(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
            'cluster' => true,
            'delivery' => 'effectively-once',
        ]);

        $bus->shouldProcess('events:outbound', '1-1');

        // The dedupe key must share the same hash tag as the stream key so they
        // land on the same Redis Cluster slot.
        $this->assertArrayHasKey('stream-bus:{events:outbound}:dedupe:1-1', $connection->kv);
    }

    // -------------------------------------------------------------------------
    // Shared / options
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Topic validation
    // -------------------------------------------------------------------------

    public function test_empty_topic_throws(): void
    {
        $bus = new StreamBus(new FakeRedisFactory(new FakeRedisConnection()), []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        $bus->publish('', []);
    }

    public function test_topic_with_whitespace_throws(): void
    {
        $bus = new StreamBus(new FakeRedisFactory(new FakeRedisConnection()), []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whitespace');

        $bus->publish('bad topic', []);
    }

    // -------------------------------------------------------------------------
    // Corrupt message handling
    // -------------------------------------------------------------------------

    public function test_corrupt_stream_message_is_returned_with_parse_error_flag(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'streams',
            'prefix' => 'stream-bus:',
        ]);

        $connection->streams['stream-bus:events:outbound']['1-1'] = ['message' => '{not valid json'];
        $connection->groups['stream-bus:events:outbound']['g1'] = true;

        $messages = $bus->read('events:outbound', ['group' => 'g1', 'consumer' => 'c1']);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]['message']['_parse_error']);
    }

    public function test_corrupt_list_message_is_returned_with_parse_error_flag(): void
    {
        $connection = new FakeRedisConnection();
        $bus = new StreamBus(new FakeRedisFactory($connection), [
            'driver' => 'lists',
            'prefix' => 'stream-bus:',
        ]);

        $connection->lists['stream-bus:events:outbound'][] = '{not valid json';

        $messages = $bus->read('events:outbound', ['block' => 0]);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]['message']['_parse_error']);
    }
}
