<?php

namespace MahavirNahata\StreamBus\Contracts;

interface StreamBusHandler
{
    /**
     * Handle an incoming message.
     *
     * @param  array  $message
     * @return void
     */
    public function handle(array $message): void;
}
