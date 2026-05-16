# Stream Bus for Laravel

A Redis-backed cross-language stream bus for Laravel. Publish from any language; consume in Laravel (or vice versa) with at-least-once or effectively-once delivery.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Redis 5.0+ (Redis 6.2+ for PEL recovery via XAUTOCLAIM)
- phpredis extension **or** predis/predis 2.x

## Installation

```bash
composer require mahavirnahata/stream-bus
```

Publish the config file:

```bash
php artisan vendor:publish --tag=stream-bus-config
```

## Quick start

**Publish a message:**

```php
use MahavirNahata\StreamBus\Facades\StreamBus;

StreamBus::publish('events:outbound', [
    'type' => 'image.process',
    'payload' => ['id' => 123],
]);
```

**Create a handler:**

```php
namespace App\Handlers;

use MahavirNahata\StreamBus\Contracts\StreamBusHandler;

class ImageResultHandler implements StreamBusHandler
{
    public function handle(array $message): void
    {
        // $message['payload'] contains your data
        dispatch(new ProcessImageResult($message['payload'] ?? []));
    }
}
```

**Start consuming:**

```bash
php artisan stream-bus:consume events:inbound App\Handlers\ImageResultHandler --group=laravel
```

---

## Drivers

| | Streams (`streams`) | Lists (`lists`) |
|---|---|---|
| Redis primitive | XADD / XREADGROUP | RPUSH / BLPOP |
| Ordering | FIFO, guaranteed | FIFO, guaranteed |
| Consumer groups | Yes | No |
| At-least-once | Yes (PEL + ACK) | Yes (pop is destructive) |
| Multi-consumer | Yes, independent | Yes, competing |
| PEL recovery | Yes (XAUTOCLAIM) | N/A |
| Cross-language | Any client that speaks Redis Streams | Any client that speaks Redis Lists |

**Use `streams`** (default) when you need consumer groups, per-message ACK, and PEL recovery after crashes.

**Use `lists`** for simpler competing-consumer queues where Redis Streams features are not required.

---

## Configuration

```php
// config/stream-bus.php
return [
    'driver'           => env('STREAM_BUS_DRIVER', 'streams'),
    'connection'       => env('STREAM_BUS_REDIS', 'default'),
    'prefix'           => env('STREAM_BUS_PREFIX', 'stream-bus:'),
    'cluster'          => env('STREAM_BUS_CLUSTER', false),
    'delivery'         => env('STREAM_BUS_DELIVERY', 'at-least-once'),
    'dedupe_ttl'       => env('STREAM_BUS_DEDUPE_TTL', 86400),
    'maxlen'           => env('STREAM_BUS_MAXLEN', null),
    'reclaim'          => env('STREAM_BUS_RECLAIM', false),
    'min_idle_time'    => env('STREAM_BUS_MIN_IDLE_TIME', 60000),
    'reclaim_count'    => env('STREAM_BUS_RECLAIM_COUNT', 10),
    'max_attempts'     => env('STREAM_BUS_MAX_ATTEMPTS', 0),
    'dead_letter_topic'=> env('STREAM_BUS_DEAD_LETTER_TOPIC', null),
    'consumers'        => [],
];
```

### Config reference

| Key | Default | Description |
|---|---|---|
| `driver` | `streams` | `streams` or `lists` |
| `connection` | `default` | Laravel Redis connection name |
| `prefix` | `stream-bus:` | Key prefix for all topics |
| `cluster` | `false` | Wrap topic in `{}` hash tags for Redis Cluster slot colocation |
| `delivery` | `at-least-once` | `at-least-once` or `effectively-once` |
| `dedupe_ttl` | `86400` | Dedup / attempt key TTL in seconds |
| `maxlen` | `null` | Max stream entries; `null` = unlimited. **Set this in production.** |
| `reclaim` | `false` | Enable automatic PEL recovery (Redis 6.2+) |
| `min_idle_time` | `60000` | ms a PEL message must be idle before reclaim |
| `reclaim_count` | `10` | Max messages to reclaim per loop |
| `max_attempts` | `0` | Max handler attempts before dead-lettering (`0` = unlimited) |
| `dead_letter_topic` | `null` | DLQ topic; defaults to `{original}:dead-letter` |
| `consumers` | `[]` | Topic → handler map (see below) |

### Consumers config

```php
'consumers' => [
    // Simple form
    'events:inbound' => App\Handlers\ImageResultHandler::class,

    // Per-topic overrides
    'events:orders' => [
        'handler'           => App\Handlers\OrderHandler::class,
        'driver'            => 'streams',
        'group'             => 'order-workers',
        'count'             => 5,
        'delivery'          => 'effectively-once',
        'dead_letter_topic' => 'events:orders:dead-letter',
    ],
],
```

---

## Publishing messages

```php
// Facade
use MahavirNahata\StreamBus\Facades\StreamBus;
StreamBus::publish('events:outbound', ['foo' => 'bar']);

// Helper function
stream_bus()->publish('events:outbound', ['foo' => 'bar']);

// Injected class
use MahavirNahata\StreamBus\StreamBus;
public function __construct(private StreamBus $bus) {}
$this->bus->publish('events:outbound', ['foo' => 'bar']);
```

Per-call option overrides:

```php
StreamBus::publish('events:outbound', $payload, [
    'driver'     => 'lists',
    'connection' => 'redis-secondary',
    'prefix'     => 'app1:bus:',
]);
```

---

## Consumer command

```bash
php artisan stream-bus:consume [topic] [handler] [options]
```

### Arguments

| Argument | Description |
|---|---|
| `topic` | Topic to consume (optional if `consumers` is configured) |
| `handler` | Handler class name (required when `topic` is given) |

### Options

| Option | Default | Description |
|---|---|---|
| `--driver` | config | `streams` or `lists` |
| `--connection` | config | Redis connection name |
| `--prefix` | config | Key prefix |
| `--group` | `default` | Consumer group (streams) |
| `--consumer` | hostname | Consumer name (streams) |
| `--count` | `1` | Messages per read (streams) |
| `--block` | `2000` | Block time: ms for streams, seconds for lists |
| `--delivery` | config | `at-least-once` or `effectively-once` |
| `--dedupe-ttl` | config | Dedup key TTL in seconds |
| `--once` | — | Read once and exit |
| `--sleep` | `200` | Sleep ms between polls when idle |
| `--no-ack` | — | Skip ACK (streams) |
| `--stop-on-error` | — | Exit if the handler throws |
| `--max-attempts` | config | Max attempts before dead-lettering (`0` = unlimited) |
| `--dead-letter-topic` | config | Override DLQ topic name |
| `--memory` | `128` | Exit when process memory exceeds this MB limit |
| `--reclaim` | config | Enable PEL reclaim each loop (Redis 6.2+) |
| `--min-idle-time` | `60000` | ms before a PEL message is eligible for reclaim |

---

## Delivery semantics

### at-least-once (default)

Messages are processed at least once. Duplicate delivery is possible after a crash. Use idempotent handlers.

### effectively-once

A Redis `SET NX EX` key is written on first processing. Duplicates within `dedupe_ttl` seconds are skipped.

```bash
php artisan stream-bus:consume events:inbound App\Handlers\Foo \
    --delivery=effectively-once \
    --dedupe-ttl=3600
```

---

## Dead-letter queue

When `max_attempts` is set and a handler fails that many times, the message is published to the dead-letter topic and ACKed (streams) or discarded (lists).

```env
STREAM_BUS_MAX_ATTEMPTS=3
STREAM_BUS_DEAD_LETTER_TOPIC=events:dead
```

Or per command:

```bash
php artisan stream-bus:consume events:inbound App\Handlers\Foo \
    --max-attempts=3 \
    --dead-letter-topic=events:dead
```

Consume dead-letter messages like any other topic:

```bash
php artisan stream-bus:consume events:dead App\Handlers\DeadLetterInspector
```

---

## PEL recovery (streams only)

When a consumer crashes while processing, its messages sit unacknowledged in Redis's Pending Entry List (PEL) forever without intervention. Enable automatic reclaim to re-queue them:

```env
STREAM_BUS_RECLAIM=true
STREAM_BUS_MIN_IDLE_TIME=60000   # 60 seconds idle before reclaiming
STREAM_BUS_RECLAIM_COUNT=10      # max reclaimed per loop
```

Or via CLI:

```bash
php artisan stream-bus:consume --reclaim --min-idle-time=60000
```

**Requires Redis 6.2+** (uses XAUTOCLAIM internally).

---

## Redis Cluster

All related keys (stream, dedupe, attempts) must land on the same hash slot. Enable hash-tag wrapping:

```env
STREAM_BUS_CLUSTER=true
```

With `cluster=true`, the topic `events:outbound` produces keys like `stream-bus:{events:outbound}`, `stream-bus:{events:outbound}:dedupe:{id}`, etc. — all guaranteed to the same slot.

---

## Stream length management

Redis Streams grow indefinitely without trimming. Set a limit in production:

```env
STREAM_BUS_MAXLEN=100000
```

Trimming uses the `~` approximate modifier (O(1)). Requires phpredis; on predis the package falls back to a separate XTRIM call (logged as a warning if that also fails).

---

## Metrics

Retrieve live stream / queue health metrics:

```php
$metrics = stream_bus()->metrics('events:outbound', 'default');

// streams result:
// [
//   'driver'  => 'streams',
//   'topic'   => 'events:outbound',
//   'key'     => 'stream-bus:events:outbound',
//   'group'   => 'default',
//   'length'  => 1500,   // total entries in stream
//   'pending' => 3,      // delivered but not yet ACKed
// ]

// lists result:
// [
//   'driver' => 'lists',
//   'topic'  => 'events:outbound',
//   'key'    => 'stream-bus:events:outbound',
//   'length' => 42,
// ]
```

A value of `-1` indicates the Redis command is unavailable for the current client version.

---

## Production deployment

### Supervisor

```ini
[program:stream-bus-inbound]
command=php /var/www/artisan stream-bus:consume events:inbound App\Handlers\ImageResultHandler
    --group=laravel
    --memory=128
    --reclaim
    --max-attempts=3
autostart=true
autorestart=true
stopwaitsecs=10
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/stream-bus-inbound.log
```

### Graceful shutdown

The consume command installs POSIX signal handlers (SIGTERM, SIGINT, SIGHUP) when the `pcntl` extension is available. On signal receipt, the current batch completes and the process exits cleanly. The default `--block=2000` means the worst-case shutdown delay is ~2 seconds.

Reduce `--block` further if you need faster shutdown response (e.g. for Kubernetes with a short grace period).

### Memory management

The `--memory=128` flag exits the process when RSS exceeds the limit, allowing Supervisor to restart it. This prevents slow memory leaks from accumulating indefinitely.

### Shared Redis

If you share Redis with other workloads, isolate the bus with a unique prefix or a dedicated Redis connection:

```env
STREAM_BUS_PREFIX=myapp:bus:
STREAM_BUS_REDIS=stream-bus-connection
```

---

## Cross-language interoperability

The wire format for every message is:

```json
{
  "id": "<uuid-v4>",
  "ts": 1716000000,
  "payload": { "your": "data" }
}
```

See `examples/` for producers and consumers in Node.js, Python, and Go.

### Go (streams)

```
examples/go-producer/main.go   — publish via XADD
examples/go-consumer/main.go   — consume via XREADGROUP / XACK
```

Run each independently:

```bash
cd examples/go-producer && go mod init producer && go get github.com/redis/go-redis/v9 github.com/google/uuid && go run main.go
cd examples/go-consumer && go mod init consumer && go get github.com/redis/go-redis/v9 && go run main.go
```

### Node.js (streams)

```
examples/node-producer.js   — publish via xAdd
examples/node-consumer.js   — consume via xReadGroup / xAck
```

```bash
npm install redis
node examples/node-producer.js
node examples/node-consumer.js
```

### Python (streams / lists)

```
examples/python-producer.py   — publish via xadd
examples/python-consumer.py   — consume via xreadgroup / xack
```

```bash
pip install redis
python examples/python-producer.py
python examples/python-consumer.py
```

---

## Troubleshooting

**Consumer exits immediately with "Handler class not found"**  
Ensure the handler class exists, is autoloaded, and implements `StreamBusHandler`.

**No messages received**  
Verify topic name and prefix match between publisher and consumer. For streams, confirm the group name matches.

**Duplicate messages**  
Expected with `at-least-once`. Switch to `effectively-once` with idempotent handlers, or use `max_attempts=1` with a dead-letter queue.

**Stream grows unbounded**  
Set `STREAM_BUS_MAXLEN` in your `.env`.

**Shutdown takes too long**  
Reduce `--block` (e.g. `--block=500`). The maximum shutdown delay equals the block time.

**PEL grows after consumer crashes**  
Enable `--reclaim` or set `STREAM_BUS_RECLAIM=true`. Requires Redis 6.2+.

---

## FAQ

**Does it scan all Redis keys?**  
No — it reads only the configured topic key with your prefix.

**Can I run multiple consumers for the same topic?**  
Yes. With the streams driver, consumers in the same group share the load. With lists, multiple consumers compete naturally.

**Exactly-once delivery?**  
Not guaranteed. Use `effectively-once` with idempotent handlers for the best-effort equivalent.

**Can I use this without Laravel?**  
The core `StreamBus` class only depends on `Illuminate\Contracts\Redis\Factory`. You can wire it up manually in any container.

---

## License

MIT
