<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\AsyncQueue\Dynamic\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Examples\AsyncQueue\Dynamic\Process\DynamicQueueConsumer;

/**
 * AsyncQueue 动态队列 Job 示例。
 *
 * Job 不会随服务启动自动执行;只有业务显式 AsyncQueue::push(DynamicJob::forServer(...)) 后才入队。
 */
class DynamicJob extends BaseJob
{
    protected mixed $data;

    public function __construct(mixed $data, ?string $queue = null)
    {
        $this->data  = $data;
        $this->queue = $queue ?: DynamicQueueConsumer::queueName();
    }

    public static function forCurrentServer(mixed $data): self
    {
        return new self($data, DynamicQueueConsumer::queueName());
    }

    public static function forServer(mixed $data, string $serverId): self
    {
        return new self($data, DynamicQueueConsumer::queueName($serverId));
    }

    public function handle(): bool
    {
        // 示例:在这里处理 $this->data。
        return true;
    }

    public function getConfig(): array
    {
        return $this->_getConfig();
    }
}
