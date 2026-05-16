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

    public function xadd(string $stream, string $id, array $fields, ?int $maxlen = null, bool $approximate = false): string
    {
        $id = $id === '*' ? $this->nextId($stream) : $id;

        $this->streams[$stream][$id] = $fields;

        if ($maxlen !== null && count($this->streams[$stream]) > $maxlen) {
            $this->streams[$stream] = array_slice($this->streams[$stream], -$maxlen, null, true);
        }

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

    public function rpush(string $key, string $value): int
    {
        $this->lists[$key][] = $value;

        return count($this->lists[$key]);
    }

    /**
     * BLPOP — pops from the head of the list (FIFO with rpush).
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

    public function set(string $key, string $value, string $ex, int $ttl, string $nx): bool
    {
        if (strtoupper($nx) === 'NX' && array_key_exists($key, $this->kv)) {
            return false;
        }

        $this->kv[$key] = $value;

        return true;
    }

    public function incr(string $key): int
    {
        $this->kv[$key] = (int) ($this->kv[$key] ?? 0) + 1;

        return (int) $this->kv[$key];
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    protected function nextId(string $stream): string
    {
        $count = isset($this->streams[$stream]) ? count($this->streams[$stream]) + 1 : 1;

        return time().'-'.$count;
    }
}
