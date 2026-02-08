<?php

use MahavirNahata\StreamBus\Contracts\StreamBusHandler;
use MahavirNahata\StreamBus\Facades\StreamBus;

class ImageResultHandler implements StreamBusHandler
{
    public function handle(array $message): void
    {
        // Example: dispatch a Laravel job with the result
        dispatch(new \App\Jobs\ProcessImageResult($message['payload'] ?? []));
    }
}

// Example publish from Laravel
StreamBus::publish('events:outbound', [
    'type' => 'image.process',
    'payload' => ['id' => 123],
]);
