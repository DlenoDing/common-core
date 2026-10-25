<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Process;

use Closure;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Crontab\Crontab;

class CrontabExecutor extends \Hyperf\Crontab\Strategy\Executor
{
    private static int $running = 0;

    private static bool $stopping = false;

    public static function running(): int
    {
        return self::$running;
    }

    public static function stopStarting(): void
    {
        self::$stopping = true;
    }

    protected function decorateRunnable(Crontab $crontab, Closure $runnable): Closure
    {
        $runnable = parent::decorateRunnable($crontab, $runnable);
        return static function () use ($runnable) {
            // Delayed worker cleanup protects started tasks, not timers that have yet to start.
            if (self::$stopping) {
                return;
            }
            // Count before any mutex IO can yield; finish after the task's finally and defer cleanup.
            ++self::$running;
            Coroutine::defer(static function () { --self::$running; });
            $runnable();
        };
    }
}
