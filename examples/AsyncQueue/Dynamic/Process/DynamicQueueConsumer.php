<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\AsyncQueue\Dynamic\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Process\Annotation\Process;

use function Hyperf\Config\config;

/**
 * AsyncQueue 动态队列消费进程示例。
 *
 * 典型用途:每台服务器消费自己的队列,Job 投递时按目标 serverId 设置 queue。
 * examples 目录默认不会被扫描；即使误扫到，isEnable() 也默认关闭真实消费进程。
 */
#[Process(name: 'CommonCoreExampleDynamicQueueConsumer', nums: 1)]
class DynamicQueueConsumer extends BaseQueueConsumer
{
    protected array $reloadChannel = ['timeout', 'failed'];

    public function getQueue(): string
    {
        $this->queue = self::queueName();
        return $this->queue;
    }

    public function getConfig(): array
    {
        $config = $this->_getConfig();
        if ($config !== []) {
            $config['concurrent']['limit'] = 20;
        }
        return $config;
    }

    public function isEnable($server): bool
    {
        if (config('app_env') === 'local') {
            return false;
        }

        return false;
    }

    public static function queueName(?string $serverId = null): string
    {
        $serverId = $serverId ?: Server::getIpAddr();
        return 'message:' . str_replace('.', '_', $serverId);
    }
}
