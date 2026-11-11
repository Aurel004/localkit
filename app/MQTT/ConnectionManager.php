<?php

namespace App\MQTT;

use Illuminate\Support\Arr;
use PhpMqtt\Client\ConnectionManager as BaseConnectionManager;
use PhpMqtt\Client\ConnectionSettings;

/**
 * Adds a `use_blocking_socket` connection setting to the php-mqtt Laravel
 * client, which exposes every ConnectionSettings option in config/mqtt-client.php
 * except this one.
 *
 * Non-blocking, the client fails any publish the socket does not take in one
 * write: fwrite() accepts only part of a large payload, the client treats the
 * short write as "Sending data over the socket failed. Has it been closed?",
 * and the broker drops the connection on the truncated packet. The camera
 * feeders' snapshot (a base64 JPEG, ~200 KB) hits this on most publishes.
 */
class ConnectionManager extends BaseConnectionManager
{
    protected function buildConnectionSettings(array $config): ConnectionSettings
    {
        return parent::buildConnectionSettings($config)
            ->useBlockingSocket((bool) Arr::get($config, 'use_blocking_socket', false));
    }
}
