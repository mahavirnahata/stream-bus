<?php

namespace MahavirNahata\StreamBus;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StreamBus
{
    protected RedisFactory $redis;

    /** @var array */
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
        $driver = $this->driver($options);
        $key = $this->key($topic, $options);

        $message = [
            'id' => (string) Str::uuid(),
            'ts' => time(),
            'payload' => $payload,
        ];

        if ($driver === 'streams') {
            $id = $this->connection($options)->xadd($key, '*', ['message' => json_encode($message)]);
            return (string) $id;
        }

        // lists driver
        $this->connection($options)->rpush($key, json_encode($message));

        return $message['id'];
    }

    /**
     * Read messages from a topic.
     *
     * For streams, uses XREADGROUP with a consumer group.
     * For lists, uses BRPOP.
     */
    public function read(string $topic, array $options = []): array
    {
        $driver = $this->driver($options);
        $key = $this->key($topic, $options);

        if ($driver === 'streams') {
            $group = $options['group'] ?? 'default';
            $consumer = $options['consumer'] ?? gethostname();
            $count = (int) ($options['count'] ?? 1);
            $block = (int) ($options['block'] ?? 5000);

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
                $decoded = json_decode($fields['message'] ?? '{}', true);
                $messages[] = [
                    'id' => $id,
                    'message' => $decoded,
                ];
            }

            return $messages;
        }

        // lists driver
        $timeout = (int) ($options['block'] ?? 5);
        $result = $this->connection($options)->brpop([$key], $timeout);

        if (! is_array($result) || count($result) < 2) {
            return [];
        }

        $decoded = json_decode($result[1] ?? '{}', true);

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
        $driver = $this->driver($options);

        if ($driver !== 'streams') {
            return 0;
        }

        $key = $this->key($topic, $options);
        $group = $options['group'] ?? 'default';
        $ids = (array) $id;

        return (int) $this->connection($options)->xack($key, $group, $ids);
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

    protected function driver(array $options): string
    {
        return $options['driver'] ?? $this->config['driver'] ?? 'streams';
    }

    public function resolvedDriver(array $options = []): string
    {
        return $this->driver($options);
    }


    protected function connection(array $options)
    {
        $connection = $options['connection'] ?? $this->config['connection'] ?? 'default';

        return $this->redis->connection($connection);
    }

    protected function key(string $topic, array $options): string
    {
        $prefix = $options['prefix'] ?? $this->config['prefix'] ?? 'stream-bus:';

        return $prefix.$topic;
    }

    protected function ensureGroupExists(string $stream, string $group, array $options = []): void
    {
        try {
            $this->connection($options)->xgroup('CREATE', $stream, $group, '0', 'MKSTREAM');
        } catch (\Throwable $e) {
            // Group may already exist. Ignore errors for idempotency.
        }
    }
}
