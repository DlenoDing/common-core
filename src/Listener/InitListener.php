<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Listener;


use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Process\Event\BeforeCoroutineHandle;
use Hyperf\Process\Event\BeforeProcessHandle;

use function Hyperf\Config\config;

#[Listener]
class InitListener implements ListenerInterface
{
    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            BootApplication::class,
            BeforeWorkerStart::class,
            BeforeProcessHandle::class,
            BeforeHandle::class,
            BeforeCoroutineHandle::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event): void
    {
        date_default_timezone_set(config('app.default_time_zone', 'Asia/Shanghai'));
    }
}