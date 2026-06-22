<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\AsyncQueue\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;

/**
 * AsyncQueue Job 示例。
 *
 * Job 类不会随服务启动自动执行;只有业务显式 AsyncQueue::push(new TestJob(...)) 后才会入队。
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
        // 示例:在这里处理 $this->data。
        return true;
    }
}
