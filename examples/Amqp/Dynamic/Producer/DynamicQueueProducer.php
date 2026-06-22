<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Dynamic\Producer;

use Dleno\CommonCore\Base\Amqp\BaseProducer;
use Dleno\CommonCore\Tools\Amqp\Producer;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Amqp\Annotation\Producer as ProducerAnnotation;

#[ProducerAnnotation(exchange: '', routingKey: '')]
class DynamicQueueProducer extends BaseProducer
{
    protected string $exchange = 'CommonCoreExampleDynamicExchange';

    protected array|string $routingKey = 'CommonCoreExampleDynamicRouting';

    public function __construct(mixed $data, int $delay = 0)
    {
        parent::__construct($data, $delay);
    }

    /**
     * 投递到指定服务器动态队列。不会被框架自动调用。
     */
    public static function sendToServer(array $data, ?string $serverId = null): bool
    {
        $serverId = $serverId ?: str_replace('.', '_', Server::getIpAddr());
        $message  = new self($data);
        $message->setRoutingKey('dynamic_' . $serverId);

        return Producer::send($message);
    }
}
