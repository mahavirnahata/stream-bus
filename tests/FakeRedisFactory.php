<?php

namespace MahavirNahata\StreamBus\Tests;

use Illuminate\Contracts\Redis\Factory;

class FakeRedisFactory implements Factory
{
    public FakeRedisConnection $connection;

    public function __construct(FakeRedisConnection $connection)
    {
        $this->connection = $connection;
    }

    public function connection($name = null)
    {
        return $this->connection;
    }

    public function resolve($name = null)
    {
        return $this->connection($name);
    }

    public function connections()
    {
        return ['default' => $this->connection];
    }

    public function __call($method, $parameters)
    {
        return $this->connection->{$method}(...$parameters);
    }
}
