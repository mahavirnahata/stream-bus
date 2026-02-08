<?php

use MahavirNahata\StreamBus\StreamBus;

if (! function_exists('stream_bus')) {
    function stream_bus(): StreamBus
    {
        return app(StreamBus::class);
    }
}
