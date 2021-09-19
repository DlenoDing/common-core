<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Amqp;

use Hyperf\Amqp\Builder\ExchangeBuilder;
use Hyperf\Amqp\Message\ProducerMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * 消息生产者（hyperf/amqp）
 * Class BaseProducer
 * @package Dleno\CommonCore\Base\Amqp
 */
class BaseProducer extends ProducerMessage
{
    /**
     * @var string
     */
    protected $poolName = 'default';//设置使用的连接池，可在__construct里动态改变

    /**
     * @var string
     */
    protected $exchange = '';//交换机key(注解优先，需要动态设置时，则不能要注解)

    /**
     * @var string
     */
    protected $routingKey = '';//路由key(注解优先，需要动态设置时，则不能要注解)

    /**
     * @var string
     */
    protected $type = \Hyperf\Amqp\Message\Type::DIRECT;

    /**
     * 是否延迟消息交换机(生产者消费者要对应)
     * @var bool
     */
    protected $delayExchange = false;

    public function __construct($data, $delay = 0)
    {
        if ($this->delayExchange && $delay > 0) {//延时消息
            $this->setProperties('application_headers', new AMQPTable([
                                                                          'x-delay' => $delay * 1000 // 延迟时间，单位毫秒
                                                                      ]));
        }
        $this->setPayload($data);
    }

    public function setProperties($properties, $val = null): self
    {
        if (is_string($properties) && !is_null($val)) {
            $this->properties[$properties] = $val;
        } elseif (is_array($properties)) {
            $this->properties = $properties;
        }
        return $this;
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
