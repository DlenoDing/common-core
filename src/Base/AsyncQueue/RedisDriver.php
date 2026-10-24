<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\Coroutine\Coroutine;

class RedisDriver extends \Hyperf\AsyncQueue\Driver\RedisDriver
{
    private array $runningCallbacks = [];

    public function consume(): void
    {
        $owner = Coroutine::id();
        try {
            parent::consume();
        } finally {
            // A callback includes ACK/retry and its finally/defer cleanup. Keep timers and connections alive until then.
            while (($this->concurrent && ! $this->concurrent->isEmpty()) || ($this->runningCallbacks[$owner] ?? 0) > 0) {
                Coroutine::sleep(0.01);
            }
        }
    }

    protected function getCallback($data, $message): callable
    {
        $callback = parent::getCallback($data, $message);
        $owner = Coroutine::id();
        return function () use ($callback, $owner) {
            $this->runningCallbacks[$owner] = ($this->runningCallbacks[$owner] ?? 0) + 1;
            // Concurrent releases its slot before coroutine defer. Register first so business defers finish first.
            Coroutine::defer(function () use ($owner) {
                if (--$this->runningCallbacks[$owner] === 0) {
                    unset($this->runningCallbacks[$owner]);
                }
            });
            return $callback();
        };
    }
}
