<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Consumer;

use Dleno\CommonCore\Base\Amqp\BaseConsumer;
use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Result;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * 【延时到死信调用】延时缓冲队列「声明」示例（TTL + 死信）。
 *
 * 作用：声明「延时缓冲队列」CommonCoreExampleDelayDlxBufferQueue，给它配上
 *   - x-message-ttl     = messageTtl 秒（消息在此滞留的延时时长）
 *   - x-dead-letter-exchange / -routing-key = 死信交换机/路由（到点后转投目标）
 * 生产者 {@see \Dleno\CommonCore\Examples\Amqp\Producer\DelayDlxProducer} 投进来的消息在此滞留 TTL 后过期，
 * 经死信交换机转投到死信队列，由 {@see DelayDlxDeadConsumer} 消费。
 *
 * ⚠️ 关键：缓冲队列【必须没有活跃消费者】，否则消息会被立即取走而不是延时，TTL 形同虚设。
 * 故本类仅用于「声明队列参数」，isEnable() 恒为 false（不会真正消费）。队列的创建可二选一：
 *   a) 把本类临时 enable 一次，让框架按上述参数建好队列后再关掉；
 *   b) 用 RabbitMQ 管理台 / 声明脚本按相同参数手工创建该队列。
 */
#[Consumer(exchange: 'CommonCoreExampleDelayDlxBufferExchange', routingKey: 'CommonCoreExampleDelayDlxBufferRouting', queue: 'CommonCoreExampleDelayDlxBufferQueue', name: 'CommonCoreExampleDelayDlxBufferConsumer', nums: 1)]
class DelayDlxBufferConsumer extends BaseConsumer
{
    protected string $poolName = 'consumer';

    protected string $exchange = 'CommonCoreExampleDelayDlxBufferExchange';

    protected array|string $routingKey = 'CommonCoreExampleDelayDlxBufferRouting';

    protected ?string $queue = 'CommonCoreExampleDelayDlxBufferQueue';

    /**
     * 死信交换机/路由：消息过期后转投到此（即下方 DelayDlxDeadConsumer 监听的交换机/路由）。
     */
    protected $deadExchange = 'CommonCoreExampleDelayDlxDeadExchange';

    protected $deadRoutingKey = 'CommonCoreExampleDelayDlxDeadRouting';

    /**
     * 消息延时时长（秒）：消息在缓冲队列滞留 10 秒后过期转入死信。
     */
    protected $messageTtl = 10;

    public function consume($data): Result
    {
        // 缓冲队列不应有活跃消费者（isEnable() 恒 false），此方法正常不会被调用。
        return Result::ACK;
    }

    /**
     * 恒关闭：缓冲队列必须无活跃消费者，消息才会滞留到 TTL 过期再进死信。
     */
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
