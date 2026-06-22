<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\TaskCron;

use Dleno\CommonCore\Tools\Logger;
use Hyperf\Crontab\Annotation\Crontab;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * 定时任务示例。
 *
 * enable 指向 isEnable(),默认 COMMON_CORE_EXAMPLE_ENABLE=false,所以即使误扫到本目录也不会真实执行。
 */
#[Crontab(name: 'CommonCoreExampleCrontab', rule: '*/5 * * * * *', callback: 'execute', enable: 'isEnable')]
class TestCrontab
{
    public function execute(): void
    {
        Logger::stdoutLog()->info(date('Y-m-d H:i:s') . ' common-core crontab example');
    }

    public function isEnable(): bool
    {
        if (!env('COMMON_CORE_EXAMPLE_ENABLE', false) || !env('ENABLE_CRONTAB', false)) {
            return false;
        }
        return config('app_env') !== 'local';
    }
}
