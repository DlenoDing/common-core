<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Amqp;

use Hyperf\Amqp\Builder\ExchangeBuilder;
use Hyperf\Amqp\Builder\QueueBuilder;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Message\Type;
use Hyperf\Process\ProcessManager;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * 消息消费者（hyperf/amqp）
 * 交换机、路由、队列设置优先级：注解>方法>属性
 * Class BaseConsumer
 * @package Dleno\CommonCore\Base\Amqp
 */
class BaseConsumer extends ConsumerMessage
{
    /**
     * @var string 交换机类型
     */
    protected $type = Type::DIRECT;

    /**
     * @var string 交换机key(注解优先，需要动态设置时，则不能要注解)
     */
    protected $exchange = '';

    /**
     * @var string 路由key(注解优先，需要动态设置时，则不能要注解)
     */
    protected $routingKey = '';

    /**
     * @var string 队列key(注解优先，需要动态设置时，则不能要注解)
     */
    protected $queue = '';

    /**
     * @var bool
     */
    protected $requeue = true;

    /**
     * @var int 最大消费数量（满足后重启进程）
     */
    protected $maxConsumption = 0;

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
     * 是否延迟消息交换机(生产者消费者要对应)
     * @var bool
     */
    protected $delayExchange = false;

    /**
     * 检查是否可以消费
     * @return bool
     */
    public function checkRunning()
    {
        if (!ProcessManager::isRunning()) {
            return false;
        }
        return true;
    }

    public function getQueueBuilder(): QueueBuilder
    {
        $builder   = parent::getQueueBuilder();
        $arguments = $builder->getArguments();
        //消息过期时间
        if ($this->messageTtl > 0) {
            $arguments['x-message-ttl'] = $this->messageTtl * 1000;
        }
        //队列过期时间
        if ($this->queueExpires > 0) {
            //有消息过期时间时，队列过期时间不能比消息过期时间小，否则自动在消息过期时间加10秒
            if ($this->messageTtl > 0 && $this->queueExpires <= $this->messageTtl) {
                $this->queueExpires = $this->messageTtl + 10;
            }
            $arguments['x-expires'] = $this->queueExpires * 1000;
        }
        //死信交换机
        if (!empty($this->deadExchange)) {
            $arguments['x-dead-letter-exchange'] = $this->deadExchange;
        }
        //死信路由
        if (!empty($this->deadRoutingKey)) {
            $arguments['x-dead-letter-routing-key'] = $this->deadRoutingKey;
        }
        $arguments = new AMQPTable($arguments);
        return $builder->setArguments($arguments);
    }

    public function getExchangeBuilder(): ExchangeBuilder
    {
        if ($this->delayExchange) {//延时消息
            return (new ExchangeBuilder())->setExchange($this->getExchange())->setType('x-delayed-message')->setArguments(
                new AMQPTable(['x-delayed-type' => $this->type])
            );
        } else {
            return parent::getExchangeBuilder();
        }
    }

    public function setPoolName($poolName): self
    {
        $this->poolName = $poolName;
        return $this;
    }
}
