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
    | Redis Cluster Mode
    |--------------------------------------------------------------------------
    | Set to true when using Redis Cluster. This wraps the topic name in
    | hash-tag braces (e.g. stream-bus:{events:outbound}) so that the stream
    | key, dedupe key, and attempts key all land on the same cluster slot.
    | Without hash tags, Lua scripts and multi-key operations fail across
    | different slots. Has no effect on standalone Redis.
    */
    'cluster' => env('STREAM_BUS_CLUSTER', false),

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
    |
    | Implementation: XTRIM is issued after every XADD. phpredis and predis
    | use different calling conventions; both are tried automatically.
    */
    'maxlen' => env('STREAM_BUS_MAXLEN', null),

    /*
    |--------------------------------------------------------------------------
    | PEL Reclaim (streams driver only)
    |--------------------------------------------------------------------------
    | When enabled, the consume command calls XAUTOCLAIM on each loop to
    | re-queue messages that were delivered to a crashed consumer and never
    | acknowledged. Requires Redis 6.2+.
    |
    | min_idle_time: milliseconds a pending message must be idle before it can
    |               be reclaimed from a crashed consumer.
    | reclaim_count: how many stale messages to reclaim per loop iteration.
    */
    'reclaim' => env('STREAM_BUS_RECLAIM', false),
    'min_idle_time' => env('STREAM_BUS_MIN_IDLE_TIME', 60000),
    'reclaim_count' => env('STREAM_BUS_RECLAIM_COUNT', 10),

    /*
    |--------------------------------------------------------------------------
    | Max Delivery Attempts
    |--------------------------------------------------------------------------
    | Maximum number of times a message is attempted before being sent to the
    | dead-letter topic. 0 = unlimited retries.
    | Can be overridden per-command with --max-attempts.
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
