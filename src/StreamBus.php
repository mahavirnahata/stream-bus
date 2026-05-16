<?php

namespace MahavirNahata\StreamBus;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StreamBus
{
    protected RedisFactory $redis;
    protected array $config;

    public function __construct(RedisFactory $redis, array $config = [])
    {
        $this->redis = $redis;
        $this->config = $config;
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
            $maxlen = $options['maxlen'] ?? $this->config['maxlen'] ?? null;

            // phpredis 4.0+ supports xadd($key, $id, $fields, $maxlen, $approximate)
            if ($maxlen !== null) {
                $id = $this->connection($options)->xadd($key, '*', ['message' => $encoded], (int) $maxlen, true);
            } else {
                $id = $this->connection($options)->xadd($key, '*', ['message' => $encoded]);
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
            $block = isset($options['block']) ? (int) $options['block'] : 5000;

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
        $timeout = (int) ($options['block'] ?? 5);
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
     */
    public function incrementAttempts(string $topic, string $id, array $options = []): int
    {
        $ttl = (int) ($options['dedupe_ttl'] ?? $this->config['dedupe_ttl'] ?? 86400);
        $key = $this->key($topic, $options).':attempts:'.$id;
        $conn = $this->connection($options);

        $attempts = (int) $conn->incr($key);
        $conn->expire($key, $ttl);

        return $attempts;
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

    protected function key(string $topic, array $options): string
    {
        $prefix = $options['prefix'] ?? $this->config['prefix'] ?? 'stream-bus:';

        return $prefix.$topic;
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

    protected function ensureGroupExists(string $stream, string $group, array $options = []): void
    {
        try {
            $this->connection($options)->xgroup('CREATE', $stream, $group, '0', 'MKSTREAM');
        } catch (\Throwable $e) {
            // Only silence "group already exists"; propagate connection and other errors
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }
}
