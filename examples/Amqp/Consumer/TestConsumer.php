<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Consumer;

use Dleno\CommonCore\Base\Amqp\BaseConsumer;
use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Result;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * AMQP 消费者示例。
 *
 * 说明:
 * - examples 目录不在 common-core 的 composer autoload 和注解扫描范围内,包安装后不会自动注册。
 * - 如需在业务项目使用,复制到 app/Amqp/Consumer 并把 namespace 改为 App\Amqp\Consumer。
 * - isEnable() 默认依赖 COMMON_CORE_EXAMPLE_ENABLE=false,即使误扫到本目录也不会启动真实消费。
 */
#[Consumer(exchange: 'TestExchange', routingKey: 'TestRouting', queue: 'TestQueue', name: 'CommonCoreExampleTestConsumer', nums: 1)]
class TestConsumer extends BaseConsumer
{
    protected string $poolName = 'consumer';

    protected string $exchange = 'TestExchange';

    protected array|string $routingKey = 'TestRouting';

    protected ?string $queue = 'TestQueue';

    /**
     * 死信交换机/路由和 TTL 按需打开;生产者与消费者的 delayExchange 必须一致。
     */
    protected $deadExchange = '';

    protected $deadRoutingKey = '';

    protected $messageTtl = 0;

    protected $queueExpires = 0;

    protected int $maxConsumption = 10000;

    protected $delayExchange = true;

    /**
     * 消费业务逻辑;抛异常才会被框架视为失败。
     * @param mixed $data 消息体
     */
    public function consume($data): Result
    {
        if (!parent::checkRunning()) {
            return Result::REQUEUE;
        }

        // 示例:在这里处理 $data,例如调用业务 Service。
        return Result::ACK;
    }

    public function isEnable(): bool
    {
        if (!env('COMMON_CORE_EXAMPLE_ENABLE', false) || !env('AMQP_ENABLE', false)) {
            return false;
        }
        return config('app_env') !== 'local';
    }
}
