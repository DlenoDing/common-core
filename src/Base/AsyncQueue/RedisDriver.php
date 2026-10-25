<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\Coroutine\Coroutine;

class RedisDriver extends \Hyperf\AsyncQueue\Driver\RedisDriver
{
    private array $inFlightCallbacks = [];

    public function consume(): void
    {
        $owner = Coroutine::id();
        try {
            parent::consume();
        } finally {
            // Wait only for this consume's prepared/running callbacks, including their ACK/retry and defer cleanup.
            while (($this->inFlightCallbacks[$owner] ?? 0) > 0) {
                Coroutine::sleep(0.01);
            }
        }
    }

    protected function getCallback($data, $message): callable
    {
        $callback = parent::getCallback($data, $message);
        $owner = Coroutine::id();
        // Account for reserved work before it waits for a Concurrent slot or starts in a child coroutine.
        $this->inFlightCallbacks[$owner] = ($this->inFlightCallbacks[$owner] ?? 0) + 1;
        return function () use ($callback, $owner) {
            // Concurrent releases its slot before coroutine defer. Register first so business defers finish first.
            Coroutine::defer(function () use ($owner) {
                if (--$this->inFlightCallbacks[$owner] === 0) {
                    unset($this->inFlightCallbacks[$owner]);
                }
            });
            return $callback();
        };
    }
}
