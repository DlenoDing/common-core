<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Process;

class CrontabDispatcherProcess extends \Hyperf\Crontab\Process\CrontabDispatcherProcess
{
    protected float $recvTimeout = 1.0;
}
