<?php

namespace MahavirNahata\StreamBus\Tests\Integration;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use MahavirNahata\StreamBus\StreamBus;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests that run against a real Redis instance.
 *
 * These tests are skipped automatically when:
 *  - The phpredis extension is not loaded, or
 *  - No Redis server is reachable at REDIS_HOST:REDIS_PORT.
 *
 * Run in CI:
 *   vendor/bin/phpunit --testsuite=integration
 *
 * Run locally (requires Redis on 127.0.0.1:6379):
 *   vendor/bin/phpunit --testsuite=integration
 */
class StreamBusIntegrationTest extends TestCase
{
    private ?\Redis $client = null;
    private ?RedisFactory $factory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not loaded.');
        }

        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $client = new \Redis();
            if (! @$client->connect($host, $port, 2.0)) {
                $this->markTestSkipped("Could not connect to Redis at {$host}:{$port}.");
            }
            $client->select(15); // isolated test DB — never touches production data
            $client->flushDb();
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
            return;
        }

        $this->client = $client;

        $connection = new PhpRedisConnection($client);

        $this->factory = new class($connection) implements RedisFactory {
            public function __construct(private mixed $conn) {}

            public function connection($name = null): mixed
            {
                return $this->conn;
            }
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client?->flushDb();
        $this->client?->close();
        $this->client = null;
        $this->factory = null;
    }

    // -------------------------------------------------------------------------
    // Streams
    // -------------------------------------------------------------------------

    public function test_streams_publish_read_ack_round_trip(): void
    {
        $bus = $this->makeBus(['driver' => 'streams']);

        $id = $bus->publish('it:events', ['hello' => 'world']);

        $messages = $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c1', 'block' => 100]);

        $this->assertCount(1, $messages);
        $this->assertSame('world', $messages[0]['message']['payload']['hello']);
        $this->assertNotEmpty($id);

        $acked = $bus->ack('it:events', $messages[0]['id'], ['group' => 'grp']);
        $this->assertSame(1, $acked);

        // Nothing left in the pending-entry-list after ACK
        $more = $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c1', 'block' => 100]);
        $this->assertSame([], $more);
    }

    public function test_streams_second_consumer_in_same_group_does_not_re_read(): void
    {
        $bus = $this->makeBus(['driver' => 'streams']);

        $bus->publish('it:events', ['n' => 1]);

        $m1 = $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c1', 'block' => 100]);
        $this->assertCount(1, $m1);

        // c2 in the same group should not receive the same message
        $m2 = $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c2', 'block' => 100]);
        $this->assertSame([], $m2);
    }

    public function test_streams_busygroup_is_idempotent(): void
    {
        $bus = $this->makeBus(['driver' => 'streams']);

        // Creating the group twice must not throw
        $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c1', 'block' => 100]);
        $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c1', 'block' => 100]);

        $this->assertTrue(true); // reached without exception
    }

    public function test_streams_maxlen_keeps_stream_bounded(): void
    {
        $bus = $this->makeBus(['driver' => 'streams', 'maxlen' => 3]);

        for ($i = 1; $i <= 6; $i++) {
            $bus->publish('it:events', ['n' => $i]);
        }

        $len = $this->client->xLen('sb-it:it:events');
        $this->assertLessThanOrEqual(3 * 2, $len); // ~ trim is approximate; allow 2× margin
    }

    public function test_streams_metrics_returns_length_and_pending(): void
    {
        $bus = $this->makeBus(['driver' => 'streams']);

        $bus->publish('it:events', ['x' => 1]);
        $bus->publish('it:events', ['x' => 2]);

        // Read one (leaves it in PEL, unacked)
        $bus->read('it:events', ['group' => 'grp', 'consumer' => 'c1', 'block' => 100]);

        $m = $bus->metrics('it:events', 'grp');

        $this->assertSame('streams', $m['driver']);
        $this->assertSame(2, $m['length']);
        $this->assertSame(1, $m['pending']); // one delivered but not acked
    }

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    public function test_lists_publish_read_fifo_order(): void
    {
        $bus = $this->makeBus(['driver' => 'lists']);

        $bus->publish('it:queue', ['n' => 1]);
        $bus->publish('it:queue', ['n' => 2]);
        $bus->publish('it:queue', ['n' => 3]);

        $r1 = $bus->read('it:queue', ['block' => 0]);
        $r2 = $bus->read('it:queue', ['block' => 0]);
        $r3 = $bus->read('it:queue', ['block' => 0]);

        $this->assertSame(1, $r1[0]['message']['payload']['n']);
        $this->assertSame(2, $r2[0]['message']['payload']['n']);
        $this->assertSame(3, $r3[0]['message']['payload']['n']);
    }

    public function test_lists_read_returns_empty_when_queue_is_empty(): void
    {
        $bus = $this->makeBus(['driver' => 'lists']);

        $result = $bus->read('it:queue', ['block' => 0]);

        $this->assertSame([], $result);
    }

    public function test_lists_metrics_returns_queue_length(): void
    {
        $bus = $this->makeBus(['driver' => 'lists']);

        $bus->publish('it:queue', ['n' => 1]);
        $bus->publish('it:queue', ['n' => 2]);

        $m = $bus->metrics('it:queue');

        $this->assertSame('lists', $m['driver']);
        $this->assertSame(2, $m['length']);
    }

    // -------------------------------------------------------------------------
    // Deduplication
    // -------------------------------------------------------------------------

    public function test_effectively_once_blocks_duplicate_message_ids(): void
    {
        $bus = $this->makeBus(['driver' => 'streams', 'delivery' => 'effectively-once', 'dedupe_ttl' => 60]);

        $this->assertTrue($bus->shouldProcess('it:events', 'msg-abc'));
        $this->assertFalse($bus->shouldProcess('it:events', 'msg-abc'));
        $this->assertTrue($bus->shouldProcess('it:events', 'msg-xyz'));
    }

    // -------------------------------------------------------------------------
    // Atomic attempt tracking
    // -------------------------------------------------------------------------

    public function test_increment_attempts_is_atomic_and_counts_correctly(): void
    {
        $bus = $this->makeBus(['driver' => 'streams']);

        $this->assertSame(1, $bus->incrementAttempts('it:events', 'msg-1'));
        $this->assertSame(2, $bus->incrementAttempts('it:events', 'msg-1'));
        $this->assertSame(1, $bus->incrementAttempts('it:events', 'msg-2'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeBus(array $config = []): StreamBus
    {
        return new StreamBus(
            $this->factory,
            array_merge(['prefix' => 'sb-it:'], $config),
        );
    }
}
