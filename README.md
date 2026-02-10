# Stream Bus for Laravel

A small Redis-backed stream bus for cross-language workers. Supports Redis Streams and Lists, configurable via `config/stream-bus.php`.

## Install (Packagist)

```bash
composer require mahavirnahata/stream-bus
```

## Install (local development)
Add a path repository and require the package:

```json
{
  "repositories": [
    { "type": "path", "url": "packages/stream-bus" }
  ],
  "require": {
    "mahavirnahata/stream-bus": "*"
  }
}
```

Publish config:

```bash
php artisan vendor:publish --tag=stream-bus-config
```

## Quick start

```php
use MahavirNahata\StreamBus\Facades\StreamBus;

StreamBus::publish('events:outbound', [
    'type' => 'image.process',
    'payload' => ['id' => 123],
]);
```

Run a consumer from Laravel:

```bash
php artisan stream-bus:consume events:inbound App\Handlers\ImageResultHandler --group=laravel
```

## End-to-end examples

### Example 1: Laravel dispatches, Node.js listens

**Laravel (dispatcher)**:

```php
use MahavirNahata\StreamBus\Facades\StreamBus;

StreamBus::publish('events:outbound', [
    'type' => 'image.process',
    'payload' => ['id' => 123],
]);
```

**Node.js (listener)**: see `examples/node-consumer.js` reading from `stream-bus:events:outbound`.

### Example 2: Node.js dispatches, Laravel listens

**Node.js (dispatcher)**: see `examples/node-producer.js` writing to `stream-bus:events:inbound`.

**Laravel (listener)**:

```php
<?php

namespace App\Handlers;

use MahavirNahata\StreamBus\Contracts\StreamBusHandler;

class ImageResultHandler implements StreamBusHandler
{
    public function handle(array $message): void
    {
        // Handle inbound data from Node.js
        event(new \\App\\Events\\ExternalResultReceived($message['payload'] ?? []));
    }
}
```

Run:

```bash
php artisan stream-bus:consume events:inbound App\\Handlers\\ImageResultHandler --group=laravel
```

## Configuration

```php
return [
    'driver' => env('STREAM_BUS_DRIVER', 'streams'), // streams|lists
    'connection' => env('STREAM_BUS_REDIS', 'default'),
    'prefix' => env('STREAM_BUS_PREFIX', 'stream-bus:'),
    'delivery' => env('STREAM_BUS_DELIVERY', 'at-least-once'), // at-least-once|effectively-once
    'dedupe_ttl' => env('STREAM_BUS_DEDUPE_TTL', 86400),
];
```

### Shared Redis guidance

This package only reads from the **configured stream/list key**, not the whole Redis instance.
If you share Redis with other apps, use a **unique prefix** or a **separate Redis connection/db**:

```env
STREAM_BUS_PREFIX=app1:bus:
STREAM_BUS_REDIS=stream-bus
```

## Publish messages

```php
use MahavirNahata\StreamBus\StreamBus;

app(StreamBus::class)->publish('events:outbound', [
    'type' => 'image.process',
    'payload' => ['id' => 123],
]);
```

## Consume from Laravel

1. Create a handler:

```php
use MahavirNahata\StreamBus\Contracts\StreamBusHandler;

class ImageResultHandler implements StreamBusHandler
{
    public function handle(array $message): void
    {
        // handle response
    }
}
```

2. Run the consumer:

```bash
php artisan stream-bus:consume events:inbound App\Handlers\ImageResultHandler --group=laravel
```

### Delivery semantics

- **at-least-once**: default
- **effectively-once**: best-effort dedupe using a Redis key per message ID

Override from CLI:

```bash
php artisan stream-bus:consume events:inbound App\Handlers\ImageResultHandler --delivery=effectively-once --dedupe-ttl=3600
```

## Examples

See `examples/` for producers and consumers in Node, Python, Go, and a Laravel handler example.

## Round trip flow (outbound -> external worker -> inbound)

1. Laravel publishes to `events:outbound`.
2. External worker consumes and processes.
3. External worker publishes results to `events:inbound`.
4. Laravel consumes `events:inbound` and dispatches app jobs.

## Consume from other languages

Examples are in `examples/`.

### Go (Streams)

- Use `XREADGROUP` on stream `stream-bus:events:outbound`
- Consumer group: `workers`
- ACK with `XACK`

### Node / Python (Streams)

- Use your Redis client to read from the stream with a consumer group
- Write responses to `stream-bus:events:inbound`

## Delivery guarantees

- **Streams** and **Lists** are **at-least-once** by default.
- **Effectively-once** uses best-effort dedupe for a configurable TTL.

## License

MIT
