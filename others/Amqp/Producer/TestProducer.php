<?php

declare(strict_types=1);

namespace App\Amqp\Producer;

use Hyperf\Amqp\Annotation\Producer;
use Dleno\CommonCore\Base\Amqp\BaseProducer;

/**
 * @Producer(exchange="", routingKey="")
 */
class TestProducer extends BaseProducer
{
    /**
     * @var string
     */
    protected $exchange = 'TestExchange';//交换机key(注解优先，需要动态设置时，则不能要注解)

    /**
     * @var string
     */
    protected $routingKey = 'TestRouting';//路由key(注解优先，需要动态设置时，则不能要注解)

    /**
     * 是否延迟消息交换机(生产者消费者要对应)
     * @var bool
     */
    protected $delayExchange = true;

    public function __construct($data, $delay = 5)
    {
        parent::__construct($data, $delay);
    }

    private function testSend()
    {
        $data = ['ssss'=>111];
        $message = new TestProducer($data);
        $routingKey = 'TestRouting1';//动态设置
        $message->setRoutingKey($routingKey);
        \Dleno\CommonCore\Tools\Amqp\Producer::send($message);
    }

}
