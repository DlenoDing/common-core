<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Process;

use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Process\ProcessManager;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * 自定义 Process 示例。
 *
 * examples 目录默认不扫描;即使误扫到,COMMON_CORE_EXAMPLE_ENABLE 默认为 false,
 * isEnable() 也会阻止服务启动时拉起真实进程。
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
        if (!env('COMMON_CORE_EXAMPLE_ENABLE', false)) {
            return false;
        }
        return config('app_env') !== 'local';
    }
}
