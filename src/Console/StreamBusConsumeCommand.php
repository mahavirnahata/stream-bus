<?php

namespace MahavirNahata\StreamBus\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use MahavirNahata\StreamBus\Contracts\StreamBusHandler;
use MahavirNahata\StreamBus\StreamBus;

class StreamBusConsumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
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
        {--sleep=200 : Sleep in ms when no messages}
        {--no-ack : Do not acknowledge messages (streams only)}
        {--stop-on-error : Exit if the handler throws}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume messages from the Stream Bus.';

    public function handle(StreamBus $bus, Container $container): int
    {
        [$topic, $handlerClass] = $this->resolveTopicAndHandler();
        $consumers = $this->resolveConfiguredConsumers();

        if ($topic && $handlerClass) {
            $consumers = [$topic => $handlerClass];
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
        ], fn ($value) => $value !== null && $value !== '');

        $ack = ! $this->option('no-ack');
        $once = (bool) $this->option('once');
        $stopOnError = (bool) $this->option('stop-on-error');
        $sleepMs = (int) $this->option('sleep');

        $handlers = $this->buildHandlers($container, $consumers);
        if ($handlers === null) {
            return self::FAILURE;
        }

        do {
            $any = false;

            foreach ($handlers as $topic => $consumer) {
                $handler = $consumer['handler'];
                $topicOptions = $this->perTopicOptions($options, $consumer['options'], count($handlers));

                $messages = $bus->read($topic, $topicOptions);

                if (empty($messages)) {
                    continue;
                }

                $any = true;

                foreach ($messages as $message) {
                    $messageId = $message['id'] ?? null;

                    $resolvedDriver = $bus->resolvedDriver($topicOptions);

                    if ($messageId && ! $bus->shouldProcess($topic, $messageId, $topicOptions)) {
                        if ($ack && $resolvedDriver !== 'lists') {
                            $bus->ack($topic, $messageId, $topicOptions);
                        }

                        continue;
                    }

                    try {
                        $handler->handle($message['message'] ?? []);
                    } catch (\Throwable $e) {
                        $this->error($e->getMessage());

                        if ($once || $stopOnError) {
                            return self::FAILURE;
                        }

                        continue;
                    }

                    if ($ack && $resolvedDriver !== 'lists') {
                        $bus->ack($topic, $messageId, $topicOptions);
                    }
                }
            }

            if (! $any) {
                if ($once) {
                    break;
                }

                usleep(max(0, $sleepMs) * 1000);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    protected function resolveTopicAndHandler(): array
    {
        $topic = $this->argument('topic');
        $handler = $this->argument('handler');

        if ($topic && $handler) {
            return [$topic, $handler];
        }

        return [$topic, $handler];
    }

    protected function resolveConfiguredConsumers(): array
    {
        $consumers = (array) config('stream-bus.consumers', []);

        return $consumers;
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
            // Avoid long blocking per stream when multiple consumers are configured.
            $options['block'] = 0;
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
        ], fn ($value) => $value !== null && $value !== '');
    }
}
