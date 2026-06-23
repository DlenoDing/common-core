<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Producer;

use Dleno\CommonCore\Base\Amqp\BaseProducer;
use Dleno\CommonCore\Tools\Amqp\Producer;
use Hyperf\Amqp\Annotation\Producer as ProducerAnnotation;

/**
 * 【延时调用】AMQP 延时生产者示例（x-delayed-message 插件方案）。
 *
 * delayExchange=true → 交换机声明为 x-delayed-message 类型（需 RabbitMQ 安装 rabbitmq_delayed_message_exchange 插件）；
 * 构造时把 $delay（秒）传进来，框架写入 x-delay 头，消息在交换机内滞留 $delay 秒后才路由到队列被消费。
 * 生产者与消费者 delayExchange 必须一致；对应 {@see \Dleno\CommonCore\Examples\Amqp\Consumer\DelayConsumer}。
 */
#[ProducerAnnotation(exchange: '', routingKey: '')]
class DelayProducer extends BaseProducer
{
    protected string $exchange = 'CommonCoreExampleDelayExchange';

    protected array|string $routingKey = 'CommonCoreExampleDelayRouting';

    protected $delayExchange = true;

    /**
     * @param mixed $data  消息体
     * @param int   $delay 延迟秒数；传 0 即立即投递
     */
    public function __construct(mixed $data, int $delay = 5)
    {
        parent::__construct($data, $delay);
    }

    /**
     * 延时投递示例：延迟 10 秒。保留为示例方法，不会被框架自动调用。
     */
    public static function sendExample(): bool
    {
        return Producer::send(new self(['id' => 1, 'payload' => 'delay-demo'], 10));
    }
}
