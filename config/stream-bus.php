<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stream Bus Driver
    |--------------------------------------------------------------------------
    | Supported: "streams", "lists"
    */
    'driver' => env('STREAM_BUS_DRIVER', 'streams'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    */
    'connection' => env('STREAM_BUS_REDIS', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Key Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env('STREAM_BUS_PREFIX', 'stream-bus:'),

    /*
    |--------------------------------------------------------------------------
    | Delivery Semantics
    |--------------------------------------------------------------------------
    | Supported: "at-least-once", "effectively-once"
    |
    | "effectively-once" uses a dedupe key to skip duplicates.
    */
    'delivery' => env('STREAM_BUS_DELIVERY', 'at-least-once'),

    /*
    |--------------------------------------------------------------------------
    | Dedupe TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'dedupe_ttl' => env('STREAM_BUS_DEDUPE_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Consumers
    |--------------------------------------------------------------------------
    | Map topics to handler classes.
    | Example:
    | 'consumers' => [
    |     'events:inbound' => App\Handlers\ImageResultHandler::class,
    | ],
    */
    'consumers' => [
        // 'topic' => HandlerClass::class,
        // 'topic' => [
        //     'handler' => HandlerClass::class,
        //     'driver' => 'streams',
        //     'group' => 'default',
        //     'consumer' => null,
        //     'count' => 1,
        //     'block' => 5000,
        //     'delivery' => 'at-least-once',
        //     'dedupe_ttl' => 86400,
        // ],
    ],
];
