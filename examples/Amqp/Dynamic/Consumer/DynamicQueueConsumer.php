<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Dynamic\Consumer;

use Dleno\CommonCore\Base\Amqp\BaseConsumer;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Result;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * 动态 routingKey / queue 消费者示例。
 *
 * 适合每台服务器消费自己的队列。默认关闭,不会随服务启动真实消费。
 */
#[Consumer(exchange: 'CommonCoreExampleDynamicExchange', name: 'CommonCoreExampleDynamicConsumer', nums: 1)]
class DynamicQueueConsumer extends BaseConsumer
{
    protected string $poolName = 'consumer';

    protected string $exchange = 'CommonCoreExampleDynamicExchange';

    protected $deadExchange = 'CommonCoreExampleDeadExchange';

    protected $deadRoutingKey = 'CommonCoreExampleDeadRouting';

    protected $messageTtl = 30;

    protected $queueExpires = 60;

    public function consume($data): Result
    {
        if (!parent::checkRunning()) {
            return Result::REQUEUE;
        }

        // 示例:处理本服务器动态队列中的消息。
        return Result::ACK;
    }

    public function getRoutingKey(): string
    {
        return 'dynamic_' . $this->serverId();
    }

    public function getQueue(): string
    {
        return 'dynamic_' . $this->serverId();
    }

    public function isEnable(): bool
    {
        if (!env('COMMON_CORE_EXAMPLE_ENABLE', false) || !env('AMQP_ENABLE', false)) {
            return false;
        }
        return config('app_env') !== 'local';
    }

    private function serverId(): string
    {
        return str_replace('.', '_', Server::getIpAddr());
    }
}
