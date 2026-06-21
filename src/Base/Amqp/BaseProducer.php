<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Amqp;

use Hyperf\Amqp\Builder\ExchangeBuilder;
use Hyperf\Amqp\Message\ProducerMessage;
use Hyperf\Amqp\Message\Type;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * 消息生产者（hyperf/amqp）
 * Class BaseProducer
 * @package Dleno\CommonCore\Base\Amqp
 */
class BaseProducer extends ProducerMessage
{
    protected string $poolName = 'default';//设置使用的连接池，可在__construct里动态改变

    protected string $exchange = '';//交换机key(注解优先，需要动态设置时，则不能要注解)

    protected array|string $routingKey = '';//路由key(注解优先，需要动态设置时，则不能要注解)

    protected string|Type $type = Type::DIRECT;

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
            //合并而非整体覆盖:保留父类默认(content_type / delivery_mode=持久化)与已设的
            //application_headers(延时消息 x-delay),避免数组形态调用把这些 wipe 掉导致丢属性/延时失效。
            $this->properties = array_merge($this->properties, $properties);
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
}
