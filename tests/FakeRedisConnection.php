<?php

namespace MahavirNahata\StreamBus\Tests;

class FakeRedisConnection
{
    public array $streams = [];
    public array $groups = [];
    public array $lists = [];
    public array $acked = [];
    public array $kv = [];

    public function xadd(string $stream, string $id, array $fields)
    {
        $id = $id === '*' ? $this->nextId($stream) : $id;

        $this->streams[$stream][$id] = $fields;

        return $id;
    }

    public function xgroup(...$args)
    {
        // Expect: CREATE, stream, group, id, MKSTREAM
        if (strtoupper($args[0]) === 'CREATE') {
            $stream = $args[1];
            $group = $args[2];

            $this->groups[$stream][$group] = true;

            if (! isset($this->streams[$stream])) {
                $this->streams[$stream] = [];
            }
        }

        return true;
    }

    public function xreadgroup($group, $consumer, array $streams, $count = 1, $block = 0)
    {
        $stream = array_key_first($streams);

        if (! isset($this->streams[$stream]) || empty($this->streams[$stream])) {
            return null;
        }

        $batch = array_slice($this->streams[$stream], 0, $count, true);

        return [$stream => $batch];
    }

    public function xack(string $stream, string $group, array $ids)
    {
        foreach ($ids as $id) {
            $this->acked[] = [$stream, $group, $id];
            unset($this->streams[$stream][$id]);
        }

        return count($ids);
    }

    public function rpush(string $key, string $value)
    {
        $this->lists[$key][] = $value;

        return count($this->lists[$key]);
    }

    public function brpop(array $keys, int $timeout)
    {
        $key = $keys[0];

        if (empty($this->lists[$key])) {
            return null;
        }

        $value = array_pop($this->lists[$key]);

        return [$key, $value];
    }

    public function set(string $key, string $value, string $ex, int $ttl, string $nx)
    {
        if (strtoupper($nx) === 'NX' && array_key_exists($key, $this->kv)) {
            return false;
        }

        $this->kv[$key] = $value;

        return true;
    }

    protected function nextId(string $stream): string
    {
        $count = isset($this->streams[$stream]) ? count($this->streams[$stream]) + 1 : 1;

        return time().'-'.$count;
    }
}
