<?php

namespace MahavirNahata\StreamBus;

use Illuminate\Support\ServiceProvider;
use MahavirNahata\StreamBus\Console\StreamBusConsumeCommand;

class StreamBusServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stream-bus.php', 'stream-bus');

        $this->app->singleton(StreamBus::class, function ($app) {
            return new StreamBus(
                $app['redis'],
                $app['config']->get('stream-bus', [])
            );
        });

        $this->app->alias(StreamBus::class, 'stream-bus');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stream-bus.php' => $this->app->configPath('stream-bus.php'),
        ], 'stream-bus-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                StreamBusConsumeCommand::class,
            ]);
        }
    }
}
