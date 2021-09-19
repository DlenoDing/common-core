<?php

namespace Dleno\CommonCore\Tools\Amqp;

class Producer
{
    /**
     * 发送MQ消息
     * @param \Hyperf\Amqp\Message\ProducerMessageInterface $producer
     * @param bool $confirm
     * @param int $timeout
     * @return bool
     */
    public static function send(
        \Hyperf\Amqp\Message\ProducerMessageInterface $producer,
        bool $confirm = false,
        int $timeout = 5
    ) {
        return get_inject_obj(\Hyperf\Amqp\Producer::class)->produce($producer, $confirm, $timeout);
    }
}
