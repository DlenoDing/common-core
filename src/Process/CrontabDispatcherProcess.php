<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Process;

use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Crontab\Strategy\ProcessStrategy;
use Hyperf\Crontab\Strategy\StrategyInterface;
use Hyperf\Crontab\Strategy\TaskWorkerStrategy;
use Hyperf\Crontab\Strategy\WorkerStrategy;
use Hyperf\Process\ProcessManager;

class CrontabDispatcherProcess extends \Hyperf\Crontab\Process\CrontabDispatcherProcess
{
    protected float $recvTimeout = 1.0;

    public function handle(): void
    {
        // Only these exact strategies dispatch elsewhere. Subclasses/custom strategies may run business here.
        $strategy = $this->container->get(StrategyInterface::class)::class;
        if ($this->process !== null && in_array($strategy, [WorkerStrategy::class, TaskWorkerStrategy::class, ProcessStrategy::class], true)) {
            Coroutine::create(static function () {
                $exit = CoordinatorManager::until(Constants::WORKER_EXIT);
                while (ProcessManager::isRunning() || CrontabExecutor::running() > 0) {
                    // Also finish if parent::handle() throws and AbstractProcess starts cleanup.
                    if ($exit->yield(1.0)) {
                        return;
                    }
                }
                $exit->resume();
            });
        }

        try {
            parent::handle();
        } finally {
            // CoroutineStrategy executes here. AbstractProcess must not clear timers/connections before it drains.
            while ($this->process !== null && CrontabExecutor::running() > 0) {
                Coroutine::sleep(0.01);
            }
        }
    }
}
