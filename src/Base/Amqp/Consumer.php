<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Amqp;

use Hyperf\Amqp\Message\ConsumerMessageInterface;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Coroutine\Coroutine;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer extends \Hyperf\Amqp\Consumer
{
    private array $runningCallbacks = [];

    protected function waitConcurrentHandled(?Concurrent $concurrent, int $interval = 10, int $count = 500): void
    {
        $owner = Coroutine::id();
        // Do not release/close a channel while a callback can still ACK on it. The container owns the stop deadline.
        while (($concurrent && ! $concurrent->isEmpty()) || ($this->runningCallbacks[$owner] ?? 0) > 0) {
            Coroutine::sleep($interval / 1000);
        }
    }

    protected function getCallback(ConsumerMessageInterface $consumerMessage, AMQPMessage $message): callable
    {
        $callback = parent::getCallback($consumerMessage, $message);
        $owner = Coroutine::id();
        return function () use ($callback, $owner) {
            $this->runningCallbacks[$owner] = ($this->runningCallbacks[$owner] ?? 0) + 1;
            Coroutine::defer(function () use ($owner) {
                if (--$this->runningCallbacks[$owner] === 0) {
                    unset($this->runningCallbacks[$owner]);
                }
            });
            return $callback();
        };
    }
}
