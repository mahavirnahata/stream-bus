<?php

namespace MahavirNahata\StreamBus\Facades;

use Illuminate\Support\Facades\Facade;

class StreamBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'stream-bus';
    }
}
