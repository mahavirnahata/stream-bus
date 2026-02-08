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
        {topic : The topic name}
        {handler : Container class name implementing StreamBusHandler}
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
        $topic = $this->argument('topic');
        $handlerClass = $this->argument('handler');

        if (! $container->bound($handlerClass) && ! class_exists($handlerClass)) {
            $this->error('Handler class not found: '.$handlerClass);
            return self::FAILURE;
        }

        $handler = $container->make($handlerClass);

        if (! $handler instanceof StreamBusHandler) {
            $this->error('Handler must implement '.StreamBusHandler::class);
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
        $resolvedDriver = $bus->resolvedDriver($options);

        do {
            $messages = $bus->read($topic, $options);

            if (empty($messages)) {
                if ($once) {
                    break;
                }

                usleep(max(0, $sleepMs) * 1000);
                continue;
            }

            foreach ($messages as $message) {
                $messageId = $message['id'] ?? null;

                if ($messageId && ! $bus->shouldProcess($topic, $messageId, $options)) {
                    if ($ack && ($options['driver'] ?? null) !== 'lists') {
                        $bus->ack($topic, $messageId, $options);
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
                    $bus->ack($topic, $messageId, $options);
                }
            }
        } while (! $once);

        return self::SUCCESS;
    }
}
