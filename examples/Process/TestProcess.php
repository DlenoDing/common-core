<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Process;

use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Process\ProcessManager;

use function Hyperf\Config\config;

/**
 * 自定义 Process 示例。
 *
 * examples 目录默认不扫描；即使误扫到，isEnable() 也默认关闭真实进程。
 */
#[Process(name: 'CommonCoreExampleProcess', enableCoroutine: true)]
class TestProcess extends AbstractProcess
{
    public string $name = 'CommonCoreExampleProcess';

    public function handle(): void
    {
        while (ProcessManager::isRunning()) {
            // 示例:这里写自定义常驻进程逻辑。
            \Swoole\Coroutine::sleep(5);
        }
    }

    public function isEnable($server): bool
    {
        if (config('app_env') === 'local') {
            return false;
        }

        return false;
    }
}
