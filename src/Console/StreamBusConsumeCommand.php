<?php

namespace MahavirNahata\StreamBus\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use MahavirNahata\StreamBus\Contracts\StreamBusHandler;
use MahavirNahata\StreamBus\StreamBus;

class StreamBusConsumeCommand extends Command
{
    protected $signature = 'stream-bus:consume
        {topic? : The topic name (optional if configured)}
        {handler? : Container class name implementing StreamBusHandler (optional if configured)}
        {--driver= : streams|lists}
        {--connection= : Redis connection name}
        {--prefix= : Key prefix override}
        {--group=default : Consumer group (streams only)}
        {--consumer= : Consumer name (streams only)}
        {--count=1 : Number of messages per read (streams only)}
        {--block=5000 : Block time in ms for streams, seconds for lists}
        {--delivery= : at-least-once|effectively-once}
        {--dedupe-ttl= : Dedupe TTL in seconds}
        {--once : Read only once and exit}
        {--sleep=200 : Sleep in ms between polls when no messages arrive}
        {--no-ack : Do not acknowledge messages (streams only)}
        {--stop-on-error : Exit if the handler throws}
        {--max-attempts=0 : Max delivery attempts before dead-lettering (0 = unlimited)}
        {--dead-letter-topic= : Override dead-letter topic name}
        {--memory=128 : Exit when process memory exceeds this limit in MB}
        {--reclaim : Reclaim stale PEL messages on each loop (streams only, requires Redis 6.2+)}
        {--min-idle-time=60000 : Min idle time in ms before a PEL message is eligible for reclaim}';

    protected $description = 'Consume messages from the Stream Bus.';

    private bool $shouldRun = true;

    public function handle(StreamBus $bus, Container $container): int
    {
        $this->registerSignalHandlers();

        [$topic, $handlerClass] = $this->resolveTopicAndHandler();
        $consumers = $this->resolveConfiguredConsumers();

        if ($topic && $handlerClass) {
            $consumers = [$topic => $handlerClass];
        } elseif ($topic && ! $handlerClass) {
            $this->error('A handler class must be provided when specifying a topic.');
            return self::FAILURE;
        } elseif (empty($consumers)) {
            $this->error('No topic/handler provided and none configured in stream-bus.php.');
            return self::FAILURE;
        }

        $options = array_filter([
            'driver' => $this->option('driver'),
            'connection' => $this->option('connection'),
            'prefix' => $this->option('prefix'),
            'group' => $this->option('group'),
            'consumer' => $this->option('consumer'),
            'count' => (int) $this->option('count'),
            'block' => (int) $this->option('block'),
            'delivery' => $this->option('delivery'),
            'dedupe_ttl' => $this->option('dedupe-ttl'),
            'dead_letter_topic' => $this->option('dead-letter-topic'),
            'min_idle_time' => (int) $this->option('min-idle-time'),
        ], fn ($value) => $value !== null && $value !== '');

        $ack = ! $this->option('no-ack');
        $once = (bool) $this->option('once');
        $stopOnError = (bool) $this->option('stop-on-error');
        $sleepMs = (int) $this->option('sleep');
        $maxAttempts = (int) $this->option('max-attempts');
        $memoryLimitMb = (int) $this->option('memory');
        $enableReclaim = (bool) $this->option('reclaim');

        $handlers = $this->buildHandlers($container, $consumers);
        if ($handlers === null) {
            return self::FAILURE;
        }

        do {
            $any = false;

            // Reclaim stale PEL messages from crashed consumers before reading new ones
            if ($enableReclaim) {
                foreach ($handlers as $reclaimTopic => $consumer) {
                    $topicOptions = $this->perTopicOptions($options, $consumer['options'], count($handlers));
                    $stale = $bus->reclaim($reclaimTopic, $topicOptions);

                    foreach ($stale as $message) {
                        $any = true;
                        $result = $this->processMessage(
                            $bus, $consumer['handler'], $reclaimTopic,
                            $message, $topicOptions, $ack, $maxAttempts, $once, $stopOnError
                        );

                        if ($result === self::FAILURE) {
                            return self::FAILURE;
                        }
                    }
                }
            }

            foreach ($handlers as $currentTopic => $consumer) {
                $handler = $consumer['handler'];
                $topicOptions = $this->perTopicOptions($options, $consumer['options'], count($handlers));

                $messages = $bus->read($currentTopic, $topicOptions);

                if (empty($messages)) {
                    continue;
                }

                $any = true;

                foreach ($messages as $message) {
                    $result = $this->processMessage(
                        $bus, $handler, $currentTopic,
                        $message, $topicOptions, $ack, $maxAttempts, $once, $stopOnError
                    );

                    if ($result === self::FAILURE) {
                        return self::FAILURE;
                    }
                }
            }

            if (! $any && ! $once) {
                usleep(max(0, $sleepMs) * 1000);
            }

            if ($memoryLimitMb > 0 && $this->isMemoryExceeded($memoryLimitMb)) {
                $this->warn("Memory limit of {$memoryLimitMb}MB exceeded. Exiting for restart.");
                return self::SUCCESS;
            }
        } while (! $once && $this->shouldRun);

        return self::SUCCESS;
    }

    private function processMessage(
        StreamBus $bus,
        StreamBusHandler $handler,
        string $topic,
        array $message,
        array $topicOptions,
        bool $ack,
        int $maxAttempts,
        bool $once,
        bool $stopOnError,
    ): int {
        $messageId = $message['id'] ?? null;
        $resolvedDriver = $bus->resolvedDriver($topicOptions);

        if ($messageId && ! $bus->shouldProcess($topic, (string) $messageId, $topicOptions)) {
            if ($ack && $resolvedDriver !== 'lists') {
                $bus->ack($topic, (string) $messageId, $topicOptions);
            }

            return self::SUCCESS;
        }

        try {
            $handler->handle($message['message'] ?? []);
        } catch (\Throwable $e) {
            $this->error("[{$topic}] {$e->getMessage()}");

            if ($maxAttempts > 0 && $messageId) {
                $attempts = $bus->incrementAttempts($topic, (string) $messageId, $topicOptions);

                if ($attempts >= $maxAttempts) {
                    $this->warn("[{$topic}] Message {$messageId} exhausted {$maxAttempts} attempts — sending to dead-letter.");
                    $bus->deadLetter($topic, $message['message'] ?? [], $topicOptions);

                    if ($ack && $resolvedDriver !== 'lists') {
                        $bus->ack($topic, (string) $messageId, $topicOptions);
                    }

                    return self::SUCCESS;
                }

                // Lists messages are already popped; re-queue for next attempt
                if ($resolvedDriver === 'lists') {
                    $bus->retry($topic, $message['message'] ?? [], $topicOptions);
                }
                // Streams messages remain in the PEL and are re-delivered via reclaim()
            }

            if ($once || $stopOnError) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if ($ack && $resolvedDriver !== 'lists' && $messageId) {
            $bus->ack($topic, (string) $messageId, $topicOptions);
        }

        return self::SUCCESS;
    }

    protected function resolveTopicAndHandler(): array
    {
        return [
            $this->argument('topic'),
            $this->argument('handler'),
        ];
    }

    protected function resolveConfiguredConsumers(): array
    {
        return (array) config('stream-bus.consumers', []);
    }

    protected function buildHandlers(Container $container, array $consumers): ?array
    {
        $handlers = [];

        foreach ($consumers as $topic => $definition) {
            $handlerClass = $this->extractHandlerClass($definition);

            if (! $handlerClass || (! $container->bound($handlerClass) && ! class_exists($handlerClass))) {
                $this->error('Handler class not found: '.$handlerClass);
                return null;
            }

            $handler = $container->make($handlerClass);

            if (! $handler instanceof StreamBusHandler) {
                $this->error('Handler must implement '.StreamBusHandler::class);
                return null;
            }

            $handlers[$topic] = [
                'handler' => $handler,
                'options' => $this->extractConsumerOptions($definition),
            ];
        }

        return $handlers;
    }

    protected function perTopicOptions(array $options, array $consumerOptions, int $consumerCount): array
    {
        $options = array_merge($options, $consumerOptions);

        if ($consumerCount > 1) {
            // With multiple consumers, use a short poll so we don't block on one
            // stream while others have messages. block=0 in Redis means "block forever",
            // not non-blocking, so we use a short positive value instead.
            $options['block'] = 50;
        }

        return $options;
    }

    protected function extractHandlerClass(mixed $definition): ?string
    {
        if (is_string($definition)) {
            return $definition;
        }

        if (is_array($definition)) {
            return $definition['handler'] ?? null;
        }

        return null;
    }

    protected function extractConsumerOptions(mixed $definition): array
    {
        if (! is_array($definition)) {
            return [];
        }

        return array_filter([
            'driver' => $definition['driver'] ?? null,
            'connection' => $definition['connection'] ?? null,
            'prefix' => $definition['prefix'] ?? null,
            'group' => $definition['group'] ?? null,
            'consumer' => $definition['consumer'] ?? null,
            'count' => $definition['count'] ?? null,
            'block' => $definition['block'] ?? null,
            'delivery' => $definition['delivery'] ?? null,
            'dedupe_ttl' => $definition['dedupe_ttl'] ?? null,
            'dead_letter_topic' => $definition['dead_letter_topic'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, function () {
                $this->shouldRun = false;
                $this->info('Shutdown signal received. Finishing current batch and exiting...');
            });
        }
    }

    private function isMemoryExceeded(int $limitMb): bool
    {
        return memory_get_usage(true) / 1024 / 1024 >= $limitMb;
    }
}
