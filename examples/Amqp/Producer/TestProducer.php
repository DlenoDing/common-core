<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Producer;

use Dleno\CommonCore\Base\Amqp\BaseProducer;
use Dleno\CommonCore\Tools\Amqp\Producer;
use Hyperf\Amqp\Annotation\Producer as ProducerAnnotation;

/**
 * AMQP 生产者示例。
 *
 * Producer 本身不会在服务启动时执行;只有业务显式 new 并调用 Producer::send() 才会投递消息。
 * 如需在业务项目使用,复制到 app/Amqp/Producer 并把 namespace 改为 App\Amqp\Producer。
 */
#[ProducerAnnotation(exchange: '', routingKey: '')]
class TestProducer extends BaseProducer
{
    protected string $exchange = 'TestExchange';

    protected array|string $routingKey = 'TestRouting';

    /**
     * 是否使用延迟消息交换机;必须与消费者保持一致。
     */
    protected $delayExchange = true;

    public function __construct(mixed $data, int $delay = 5)
    {
        parent::__construct($data, $delay);
    }

    /**
     * 发送示例。保留为示例方法,不会被框架自动调用。
     */
    public static function sendExample(): bool
    {
        $message = new self(['id' => 1, 'payload' => 'demo']);

        // 如需动态路由,可在发送前覆盖 routingKey。
        // $message->setRoutingKey('TestRoutingDynamic');

        return Producer::send($message);
    }
}
