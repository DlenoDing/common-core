<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Process;

use Swoole\Server;
use Swoole\Timer;

class WorkerExitCallback extends \Hyperf\Framework\Bootstrap\WorkerExitCallback
{
    /** null: not draining; int: keep-alive timer; false: drained or timer unavailable. */
    private int|false|null $keepAlive = null;

    public function onWorkerExit(Server $server, int $workerId)
    {
        if (CrontabExecutor::running() > 0 && $this->keepAlive !== false) {
            if ($this->keepAlive === null) {
                CrontabExecutor::stopStarting();
                // Keep the native callback polling until tasks finish; an idle reactor would skip it.
                $this->keepAlive = Timer::tick(10, static function () {});
                if ($this->keepAlive === false) {
                    error_log('Cron drain timer unavailable; worker exit cleanup was not delayed.');
                }
            }
            if ($this->keepAlive !== false) {
                return;
            }
        } elseif (is_int($this->keepAlive)) {
            Timer::clear($this->keepAlive);
            $this->keepAlive = false;
        }

        parent::onWorkerExit($server, $workerId);
    }
}
