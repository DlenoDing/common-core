<?php
namespace App\TaskCron;

use Dleno\CommonCore\Tools\Logger;
use Hyperf\Crontab\Annotation\Crontab;

/**
 * @Crontab(name="TestCrontab", rule="*\/5 * * * * *", callback="execute", onOneServer=true)
 */
class TestCrontab
{
    public function execute()
    {
        Logger::stdoutLog()->info(date('Y-m-d H:i:s').'=============');
    }
}
