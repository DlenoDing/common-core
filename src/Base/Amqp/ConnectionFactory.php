<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Amqp;

use Hyperf\Amqp\AMQPConnection;
use InvalidArgumentException;

class ConnectionFactory extends \Hyperf\Amqp\ConnectionFactory
{
    public function make(array $config): AMQPConnection
    {
        $heartbeat = $config['params']['heartbeat'] ?? 15;
        if (!is_int($heartbeat) || $heartbeat <= 0) {
            throw new InvalidArgumentException('AMQP params.heartbeat must be a positive integer; omit it to use 15 seconds.');
        }
        $config['params']['heartbeat'] = $heartbeat;
        $config['params']['read_write_timeout'] ??= 30;

        return parent::make($config);
    }
}
