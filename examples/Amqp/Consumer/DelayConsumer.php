<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Consumer;

use Dleno\CommonCore\Base\Amqp\BaseConsumer;
use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Result;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * 【延时调用】AMQP 延时消费者示例（x-delayed-message 插件方案），与 {@see \Dleno\CommonCore\Examples\Amqp\Producer\DelayProducer} 配对。
 *
 * delayExchange=true 必须与生产者一致，否则交换机类型（x-delayed-message）声明冲突。
 * 延时到点的消息才会进入本队列被消费。
 */
#[Consumer(exchange: 'CommonCoreExampleDelayExchange', routingKey: 'CommonCoreExampleDelayRouting', queue: 'CommonCoreExampleDelayQueue', name: 'CommonCoreExampleDelayConsumer', nums: 1)]
class DelayConsumer extends BaseConsumer
{
    protected string $poolName = 'consumer';

    protected string $exchange = 'CommonCoreExampleDelayExchange';

    protected array|string $routingKey = 'CommonCoreExampleDelayRouting';

    protected ?string $queue = 'CommonCoreExampleDelayQueue';

    /**
     * 与生产者一致：x-delayed-message 交换机。
     */
    protected $delayExchange = true;

    public function consume($data): Result
    {
        if (!parent::checkRunning()) {
            return Result::REQUEUE;
        }

        // 示例：延时到点后在这里处理 $data。
        return Result::ACK;
    }

    public function isEnable(): bool
    {
        if (!env('AMQP_ENABLE', false)) {
            return false;
        }

        if (config('app_env') === 'local') {
            return false;
        }

        return false;
    }
}
