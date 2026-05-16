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
    | "effectively-once" uses a dedupe key to skip duplicate message IDs.
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
    | Stream Max Length
    |--------------------------------------------------------------------------
    | Maximum number of entries to keep in a Redis Stream (approximate trim).
    | null = unlimited (stream grows forever — set a limit in production).
    | Requires phpredis 4.0+. Has no effect on the lists driver.
    */
    'maxlen' => env('STREAM_BUS_MAXLEN', null),

    /*
    |--------------------------------------------------------------------------
    | PEL Reclaim Settings (streams driver only)
    |--------------------------------------------------------------------------
    | min_idle_time: milliseconds a pending message must be idle before it can
    |               be reclaimed from a crashed consumer. Requires Redis 6.2+.
    | reclaim_count: how many stale messages to reclaim per loop iteration.
    */
    'min_idle_time' => env('STREAM_BUS_MIN_IDLE_TIME', 60000),
    'reclaim_count' => env('STREAM_BUS_RECLAIM_COUNT', 10),

    /*
    |--------------------------------------------------------------------------
    | Max Delivery Attempts
    |--------------------------------------------------------------------------
    | Maximum number of times a message is attempted before being sent to the
    | dead-letter topic. 0 = unlimited retries.
    */
    'max_attempts' => env('STREAM_BUS_MAX_ATTEMPTS', 0),

    /*
    |--------------------------------------------------------------------------
    | Dead-Letter Topic
    |--------------------------------------------------------------------------
    | Topic where exhausted messages are published. Defaults to
    | "{original-topic}:dead-letter" when null.
    */
    'dead_letter_topic' => env('STREAM_BUS_DEAD_LETTER_TOPIC', null),

    /*
    |--------------------------------------------------------------------------
    | Consumers
    |--------------------------------------------------------------------------
    | Map topics to handler classes. Each entry can be a class name string or
    | an array with per-topic overrides.
    |
    | Example:
    | 'consumers' => [
    |     'events:inbound' => App\Handlers\ImageResultHandler::class,
    |     'events:orders'  => [
    |         'handler'          => App\Handlers\OrderHandler::class,
    |         'driver'           => 'streams',
    |         'group'            => 'workers',
    |         'consumer'         => null,
    |         'count'            => 5,
    |         'block'            => 5000,
    |         'delivery'         => 'at-least-once',
    |         'dedupe_ttl'       => 86400,
    |         'dead_letter_topic'=> 'events:orders:dead-letter',
    |     ],
    | ],
    */
    'consumers' => [
        // 'topic' => HandlerClass::class,
    ],
];
