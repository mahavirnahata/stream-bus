<?php

namespace MahavirNahata\StreamBus;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class StreamBus
{
    protected RedisFactory $redis;
    protected array $config;
    protected LoggerInterface $logger;

    public function __construct(RedisFactory $redis, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->redis = $redis;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Publish a message to a topic.
     */
    public function publish(string $topic, array $payload, array $options = []): string
    {
        $this->validateTopic($topic);

        $driver = $this->driver($options);
        $key = $this->key($topic, $options);

        $message = [
            'id' => (string) Str::uuid(),
            'ts' => time(),
            'payload' => $payload,
        ];

        $encoded = json_encode($message, JSON_THROW_ON_ERROR);

        if ($driver === 'streams') {
            $id = $this->connection($options)->xadd($key, '*', ['message' => $encoded]);

            $maxlen = $options['maxlen'] ?? $this->config['maxlen'] ?? null;
            if ($maxlen !== null) {
                $this->trimStream($key, (int) $maxlen, $options);
            }

            return (string) $id;
        }

        // lists driver — FIFO: rpush to tail, blpop from head
        $this->connection($options)->rpush($key, $encoded);

        return $message['id'];
    }

    /**
     * Read messages from a topic.
     *
     * For streams, uses XREADGROUP with a consumer group.
     * For lists, uses BLPOP (FIFO: head of list).
     */
    public function read(string $topic, array $options = []): array
    {
        $this->validateTopic($topic);

        $driver = $this->driver($options);
        $key = $this->key($topic, $options);

        if ($driver === 'streams') {
            $group = $options['group'] ?? 'default';
            $consumer = $options['consumer'] ?? $this->resolveConsumerName();
            $count = (int) ($options['count'] ?? 1);
            $block = isset($options['block']) ? (int) $options['block'] : 2000;

            $this->ensureGroupExists($key, $group, $options);

            $result = $this->connection($options)->xreadgroup(
                $group,
                $consumer,
                [$key => '>'],
                $count,
                $block
            );

            if (! is_array($result) || empty($result[$key])) {
                return [];
            }

            $messages = [];
            foreach ($result[$key] as $id => $fields) {
                $messages[] = [
                    'id' => $id,
                    'message' => $this->decodeMessage($fields['message'] ?? '{}'),
                ];
            }

            return $messages;
        }

        // lists driver — FIFO: blpop from head (rpush + blpop = queue, not stack)
        $timeout = (int) ($options['block'] ?? 2);
        $result = $this->connection($options)->blpop([$key], $timeout);

        if (! is_array($result) || count($result) < 2) {
            return [];
        }

        $decoded = $this->decodeMessage($result[1] ?? '{}');

        return [[
            'id' => Arr::get($decoded, 'id'),
            'message' => $decoded,
        ]];
    }

    /**
     * Acknowledge a processed message (streams only).
     */
    public function ack(string $topic, string|array $id, array $options = []): int
    {
        if ($this->driver($options) !== 'streams') {
            return 0;
        }

        $key = $this->key($topic, $options);
        $group = $options['group'] ?? 'default';

        return (int) $this->connection($options)->xack($key, $group, (array) $id);
    }

    /**
     * Reclaim stale pending-entry-list messages from crashed consumers (streams only).
     * Requires Redis 6.2+ and phpredis 5.3+.
     */
    public function reclaim(string $topic, array $options = []): array
    {
        if ($this->driver($options) !== 'streams') {
            return [];
        }

        $key = $this->key($topic, $options);
        $group = $options['group'] ?? 'default';
        $consumer = $options['consumer'] ?? $this->resolveConsumerName();
        $minIdleMs = (int) ($options['min_idle_time'] ?? $this->config['min_idle_time'] ?? 60000);
        $count = (int) ($options['reclaim_count'] ?? $this->config['reclaim_count'] ?? 10);

        $result = $this->connection($options)->xautoclaim($key, $group, $consumer, $minIdleMs, '0-0', $count);

        if (! is_array($result) || empty($result[1])) {
            return [];
        }

        $messages = [];
        foreach ($result[1] as $id => $fields) {
            $messages[] = [
                'id' => $id,
                'message' => $this->decodeMessage($fields['message'] ?? '{}'),
            ];
        }

        return $messages;
    }

    /**
     * Determine if a message should be processed (best-effort dedupe).
     */
    public function shouldProcess(string $topic, string $id, array $options = []): bool
    {
        $delivery = $options['delivery'] ?? $this->config['delivery'] ?? 'at-least-once';

        if ($delivery !== 'effectively-once') {
            return true;
        }

        $ttl = (int) ($options['dedupe_ttl'] ?? $this->config['dedupe_ttl'] ?? 86400);
        $key = $this->key($topic, $options).':dedupe:'.$id;

        return (bool) $this->connection($options)->set($key, '1', 'EX', $ttl, 'NX');
    }

    /**
     * Increment and return the delivery attempt count for a message.
     *
     * Uses a Lua script so INCR and EXPIRE are atomic — a process crash between
     * the two commands cannot leave a key with no TTL.
     *
     * Laravel normalises eval args for both phpredis and predis:
     *   eval($script, $numKeys, $key1, ..., $arg1, ...)
     */
    public function incrementAttempts(string $topic, string $id, array $options = []): int
    {
        $ttl = (int) ($options['dedupe_ttl'] ?? $this->config['dedupe_ttl'] ?? 86400);
        $key = $this->key($topic, $options).':attempts:'.$id;

        $lua = <<<'LUA'
local v = redis.call('INCR', KEYS[1])
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
return v
LUA;

        return (int) $this->connection($options)->eval($lua, 1, $key, $ttl);
    }

    /**
     * Re-queue a failed message for retry on the lists driver.
     * Streams messages stay in the PEL and are re-delivered automatically.
     */
    public function retry(string $topic, array $message, array $options = []): void
    {
        if ($this->driver($options) !== 'lists') {
            return;
        }

        $key = $this->key($topic, $options);
        $message['_attempt'] = ($message['_attempt'] ?? 0) + 1;

        $this->connection($options)->rpush($key, json_encode($message, JSON_THROW_ON_ERROR));
    }

    /**
     * Publish a failed message to the dead-letter topic.
     */
    public function deadLetter(string $topic, array $message, array $options = []): string
    {
        $dlTopic = $options['dead_letter_topic'] ?? $this->config['dead_letter_topic'] ?? $topic.':dead-letter';
        $payload = $message['payload'] ?? $message;

        return $this->publish($dlTopic, array_merge((array) $payload, ['_origin_topic' => $topic]), $options);
    }

    /**
     * Return basic health metrics for a topic.
     *
     * For streams: stream length and pending-entry-list size for the group.
     * For lists:   list length.
     *
     * Values are -1 when the underlying Redis command is unavailable.
     */
    public function metrics(string $topic, string $group = 'default', array $options = []): array
    {
        $driver = $this->driver($options);
        $key = $this->key($topic, $options);
        $conn = $this->connection($options);
        $base = ['driver' => $driver, 'topic' => $topic, 'key' => $key];

        if ($driver === 'streams') {
            try {
                $length = (int) $conn->xlen($key);
            } catch (\Throwable) {
                $length = -1;
            }

            try {
                $raw = $conn->xpending($key, $group);
                $pending = is_array($raw) ? (int) ($raw[0] ?? 0) : 0;
            } catch (\Throwable) {
                $pending = -1;
            }

            return array_merge($base, ['group' => $group, 'length' => $length, 'pending' => $pending]);
        }

        try {
            $length = (int) $conn->llen($key);
        } catch (\Throwable) {
            $length = -1;
        }

        return array_merge($base, ['length' => $length]);
    }

    protected function driver(array $options): string
    {
        return $options['driver'] ?? $this->config['driver'] ?? 'streams';
    }

    public function resolvedDriver(array $options = []): string
    {
        return $this->driver($options);
    }

    protected function connection(array $options): mixed
    {
        $connection = $options['connection'] ?? $this->config['connection'] ?? 'default';

        return $this->redis->connection($connection);
    }

    /**
     * Build the Redis key for a topic.
     *
     * When cluster=true the topic is wrapped in {braces} so that the stream key,
     * dedupe key, and attempts key all hash to the same Redis Cluster slot.
     * Without hash tags those keys may land on different nodes, breaking Lua
     * scripts and multi-key operations.
     */
    protected function key(string $topic, array $options): string
    {
        $prefix = $options['prefix'] ?? $this->config['prefix'] ?? 'stream-bus:';
        $cluster = $options['cluster'] ?? $this->config['cluster'] ?? false;

        return $cluster
            ? $prefix.'{'.$topic.'}'
            : $prefix.$topic;
    }

    protected function validateTopic(string $topic): void
    {
        if ($topic === '') {
            throw new \InvalidArgumentException('Stream Bus topic name cannot be empty.');
        }

        if (preg_match('/\s/', $topic)) {
            throw new \InvalidArgumentException("Stream Bus topic name must not contain whitespace: [{$topic}]");
        }
    }

    protected function resolveConsumerName(): string
    {
        return gethostname() ?: 'consumer-'.getmypid();
    }

    protected function decodeMessage(string $raw): array
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException) {
            return ['_raw' => $raw, '_parse_error' => true];
        }
    }

    /**
     * Trim a Redis Stream to at most $maxlen entries (approximate).
     *
     * phpredis and predis use different calling conventions for XTRIM, so we
     * detect the underlying client and call accordingly. Both branches produce
     * an approximate trim with the '~' modifier, which is O(1) and safe for
     * high-throughput streams.
     *
     * A class_exists guard is required because \Redis (the phpredis extension
     * class) does not exist when only predis is installed; without the guard,
     * `instanceof \Redis` would throw a fatal \Error.
     */
    protected function trimStream(string $key, int $maxlen, array $options): void
    {
        $conn = $this->connection($options);

        // phpredis: client() returns a \Redis instance; predis returns a Predis\Client
        if (class_exists(\Redis::class)
            && method_exists($conn, 'client')
            && $conn->client() instanceof \Redis
        ) {
            $conn->xtrim($key, $maxlen, true);

            return;
        }

        // Predis 2.x / unknown clients: xtrim($key, 'MAXLEN', '~', $count)
        try {
            $conn->xtrim($key, 'MAXLEN', '~', $maxlen);
        } catch (\Throwable $e) {
            $this->logger->warning('StreamBus: stream trim failed; stream will grow unbounded until managed externally.', [
                'key' => $key,
                'maxlen' => $maxlen,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function ensureGroupExists(string $stream, string $group, array $options = []): void
    {
        try {
            $this->connection($options)->xgroup('CREATE', $stream, $group, '0', 'MKSTREAM');
        } catch (\Throwable $e) {
            // Only silence "group already exists"; propagate connection and all other errors
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }
}
