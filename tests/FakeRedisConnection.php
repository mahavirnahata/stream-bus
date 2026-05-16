<?php

namespace MahavirNahata\StreamBus\Tests;

class FakeRedisConnection
{
    public array $streams = [];
    public array $groups = [];
    public array $lists = [];
    public array $acked = [];
    public array $kv = [];
    public array $groupsCreated = [];

    public function xadd(string $stream, string $id, array $fields): string
    {
        $id = $id === '*' ? $this->nextId($stream) : $id;

        $this->streams[$stream][$id] = $fields;

        return $id;
    }

    public function xgroup(...$args): bool
    {
        if (strtoupper($args[0]) === 'CREATE') {
            $stream = $args[1];
            $group = $args[2];

            $this->groups[$stream][$group] = true;
            $this->groupsCreated[] = [$stream, $group];

            if (! isset($this->streams[$stream])) {
                $this->streams[$stream] = [];
            }
        }

        return true;
    }

    public function xreadgroup(string $group, string $consumer, array $streams, int $count = 1, int $block = 0): ?array
    {
        $stream = array_key_first($streams);

        if (! isset($this->streams[$stream]) || empty($this->streams[$stream])) {
            return null;
        }

        $batch = array_slice($this->streams[$stream], 0, $count, true);

        return [$stream => $batch];
    }

    public function xack(string $stream, string $group, array $ids): int
    {
        foreach ($ids as $id) {
            $this->acked[] = [$stream, $group, $id];
            unset($this->streams[$stream][$id]);
        }

        return count($ids);
    }

    /**
     * Simulate XAUTOCLAIM: return pending messages older than $minIdleMs.
     * Returns [nextCursor, [id => fields, ...]] (Redis 6.2+ format).
     */
    public function xautoclaim(string $stream, string $group, string $consumer, int $minIdleMs, string $start, int $count = 10): array
    {
        if (empty($this->streams[$stream])) {
            return ['0-0', []];
        }

        $batch = array_slice($this->streams[$stream], 0, $count, true);

        return ['0-0', $batch];
    }

    /**
     * XTRIM — trims the stream to at most $maxlen entries.
     *
     * Accepts both phpredis-style args (int $maxlen, bool $approximate) and
     * predis-style args ('MAXLEN', '~', int $maxlen) by scanning for the int.
     */
    public function xtrim(string $key, mixed ...$args): int
    {
        $maxlen = null;
        foreach ($args as $arg) {
            if (is_int($arg) && $arg > 0) {
                $maxlen = $arg;
                break;
            }
        }

        if ($maxlen === null || empty($this->streams[$key])) {
            return 0;
        }

        $count = count($this->streams[$key]);
        if ($count <= $maxlen) {
            return 0;
        }

        $trimmed = $count - $maxlen;
        $this->streams[$key] = array_slice($this->streams[$key], -$maxlen, null, true);

        return $trimmed;
    }

    /** Returns the number of entries in a stream. */
    public function xlen(string $stream): int
    {
        return count($this->streams[$stream] ?? []);
    }

    /**
     * XPENDING summary form: [count, smallest-id, largest-id, [[consumer, count], ...]].
     * In the fake, all stream entries are treated as pending (not yet acked).
     */
    public function xpending(string $stream, string $group, mixed ...$args): array
    {
        $count = count($this->streams[$stream] ?? []);

        return [$count, null, null, null];
    }

    public function rpush(string $key, string $value): int
    {
        $this->lists[$key][] = $value;

        return count($this->lists[$key]);
    }

    /**
     * BLPOP — pops from the head of the list (FIFO when paired with rpush).
     */
    public function blpop(array $keys, int $timeout): ?array
    {
        $key = $keys[0];

        if (empty($this->lists[$key])) {
            return null;
        }

        $value = array_shift($this->lists[$key]);

        return [$key, $value];
    }

    /** Returns the number of entries in a list. */
    public function llen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }

    public function set(string $key, string $value, string $ex, int $ttl, string $nx): bool
    {
        if (strtoupper($nx) === 'NX' && array_key_exists($key, $this->kv)) {
            return false;
        }

        $this->kv[$key] = $value;

        return true;
    }

    /**
     * Simulate the atomic INCR + EXPIRE Lua script used by incrementAttempts().
     *
     * Laravel normalises eval args for both phpredis and predis as:
     *   eval($script, $numKeys, $key1, ..., $arg1, ...)
     * So KEYS[1] = $args[0] and ARGV[1] = $args[1] when $numKeys = 1.
     */
    public function eval(string $script, int $numkeys, mixed ...$args): mixed
    {
        $keys = array_slice($args, 0, $numkeys);

        // Detect the INCR + EXPIRE pattern used by incrementAttempts()
        if ($numkeys === 1 && str_contains($script, 'INCR') && str_contains($script, 'EXPIRE')) {
            $key = $keys[0];
            $this->kv[$key] = (int) ($this->kv[$key] ?? 0) + 1;

            return (int) $this->kv[$key];
        }

        return null;
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function del(string ...$keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->kv)) {
                unset($this->kv[$key]);
                $count++;
            }
        }

        return $count;
    }

    protected function nextId(string $stream): string
    {
        $count = isset($this->streams[$stream]) ? count($this->streams[$stream]) + 1 : 1;

        return time().'-'.$count;
    }
}
