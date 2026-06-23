<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Producer;

use Dleno\CommonCore\Base\Amqp\BaseProducer;
use Dleno\CommonCore\Tools\Amqp\Producer;
use Hyperf\Amqp\Annotation\Producer as ProducerAnnotation;

/**
 * 【延时到死信调用】AMQP 延时（TTL + 死信）生产者示例 —— 不依赖延时插件的延时方案。
 *
 * 流程：本生产者把消息投到「延时缓冲交换机」→ 路由进「延时缓冲队列」(带 x-message-ttl 延时 + 死信转投，且无活跃消费者)；
 * 消息在缓冲队列滞留 TTL 秒后过期，经死信交换机转投到「死信队列」，由
 * {@see \Dleno\CommonCore\Examples\Amqp\Consumer\DelayDlxDeadConsumer} 消费 —— 即“延时到点才被处理”。
 *
 * 缓冲队列的 TTL/死信参数见 {@see \Dleno\CommonCore\Examples\Amqp\Consumer\DelayDlxBufferConsumer}。
 * 本生产者是普通直连投递（delayExchange=false），延时由缓冲队列的 TTL 决定，与生产者无关。
 */
#[ProducerAnnotation(exchange: '', routingKey: '')]
class DelayDlxProducer extends BaseProducer
{
    protected string $exchange = 'CommonCoreExampleDelayDlxBufferExchange';

    protected array|string $routingKey = 'CommonCoreExampleDelayDlxBufferRouting';

    public function __construct(mixed $data)
    {
        parent::__construct($data);
    }

    /**
     * 投递到延时缓冲队列示例。保留为示例方法，不会被框架自动调用。
     * 消息会在缓冲队列滞留 messageTtl 秒后转入死信队列被消费。
     */
    public static function sendExample(): bool
    {
        return Producer::send(new self(['id' => 1, 'payload' => 'delay-dlx-demo']));
    }
}
