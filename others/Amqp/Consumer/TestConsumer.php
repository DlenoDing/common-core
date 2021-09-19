<?php

declare(strict_types=1);

namespace App\Amqp\Consumer;

use Hyperf\Amqp\Result;
use Hyperf\Amqp\Annotation\Consumer;
use Dleno\CommonCore\Base\Amqp\BaseConsumer;
use Dleno\CommonCore\Tools\Server;

/**
 * 优先级：注解>方法>属性
 * @Consumer(exchange="TestExchange", routingKey1="TestRouting", queue1="TestQueue", name ="TestConsumer", nums=1, enable=true)
 */
class TestConsumer extends BaseConsumer
{
    /**
     * @var string 交换机key(注解优先，需要动态设置时，则不能要注解)
     */
    protected $exchange = 'TestExchange';

    /**
     * @var string 路由key(注解优先，需要动态设置时，则不能要注解)
     */
    protected $routingKey = 'TestRouting';

    /**
     * @var string 队列key(注解优先，需要动态设置时，则不能要注解)
     */
    protected $queue = 'TestQueue';

    /**
     * @var string 死信交换机
     */
    protected $deadExchange = '';

    /**
     * @var string 死信路由
     */
    protected $deadRoutingKey = '';

    /**
     * @var int 消息过期时间（秒）
     */
    protected $messageTtl = 0;

    /**
     * @var int 队列过期时间[对应时间没有消费者，应大于消息过期时间]（秒）
     */
    protected $queueExpires = 0;

    /**
     * @var int
     */
    protected $maxConsumption = 10000;

    /**
     * 是否延迟消息交换机(生产者消费者要对应)
     * @var bool
     */
    protected $delayExchange = true;

    /**
     * 消费业务逻辑
     * @param $data
     * @return string
     */
    public function consume($data): string
    {
        if (!parent::checkRunning()) {
            return Result::REQUEUE;//不处理，重新入队列
        }

        //消息消费业务逻辑
        print_r(time());
        print_r($data);

        return Result::ACK;
    }

    public function getRoutingKey1()
    {
        //自定义RoutingKey
        return 'PUSH_'.Server::getIpAddr();
    }

    public function getQueue1(): string
    {
        //自定义Queue
        return 'QUEUE_'.Server::getIpAddr();
    }
}
