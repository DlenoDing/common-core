<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\AsyncQueue\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Hyperf\Process\Annotation\Process;

use function Hyperf\Config\config;

/**
 * AsyncQueue 消费进程示例。
 *
 * examples 目录默认不会被扫描；即使误扫到，isEnable() 也默认关闭真实消费进程。
 */
#[Process(name: 'CommonCoreExampleQueueConsumer', nums: 1)]
class TestAsyncQueueConsumer extends BaseQueueConsumer
{
    protected string $queue = 'test';

    protected array $reloadChannel = ['timeout', 'failed'];

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
}
