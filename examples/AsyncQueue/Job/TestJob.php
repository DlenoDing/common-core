<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\AsyncQueue\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\AsyncQueue\AsyncQueue;

/**
 * AsyncQueue Job 示例（redis 驱动）。
 *
 * Job 类不会随服务启动自动执行；只有业务显式 AsyncQueue::push(new TestJob(...)) 后才会入队。
 * redis 异步队列支持两种投递：普通（立即）与延时；分别见 pushNormalExample() / pushDelayExample()。
 * （死信队列是 AMQP 的能力，redis 异步队列没有，故 redis 只演示普通 + 延时两种。）
 */
class TestJob extends BaseJob
{
    protected $queue = 'test';

    protected mixed $data;

    public function __construct(mixed $data)
    {
        $this->data = $data;
    }

    public function handle(): bool
    {
        // 示例：在这里处理 $this->data。
        return true;
    }

    /**
     * 让 AsyncQueue::push 能为本队列(test)自动注册驱动配置：复用 async_queue.default 的驱动配置，
     * 仅把 channel 换成本队列名。若已在 config/autoload/async_queue.php 显式配置了 'test' 队列，可删掉本方法。
     */
    public function getConfig()
    {
        return $this->_getConfig();
    }

    /**
     * 【普通调用】立即投递：入队后由消费进程尽快取出执行 handle()。
     * 保留为示例方法，不会被框架自动调用。
     */
    public static function pushNormalExample(): bool
    {
        return AsyncQueue::push(new self(['id' => 1, 'payload' => 'demo']));
    }

    /**
     * 【延时调用】延时投递：AsyncQueue::push 第二个参数 $delay（秒）即延时时长。
     * redis 异步队列先把消息放入 delayed 通道，到点后自动转入 waiting 通道再被消费。
     * 保留为示例方法，不会被框架自动调用。
     */
    public static function pushDelayExample(): bool
    {
        // 延迟 10 秒后再被消费
        return AsyncQueue::push(new self(['id' => 2, 'payload' => 'delayed-demo']), 10);
    }
}
