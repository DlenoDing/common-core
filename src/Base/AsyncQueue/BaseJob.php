<?php
declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\AsyncQueue\Job;

abstract class BaseJob extends Job
{
    /**
     * @var string 与消费进程对应
     */
    protected $queue = 'default';

    /**
     * 任务执行失败后的重试次数，即最大执行次数为 $maxAttempts+1 次
     *
     * @var int
     */
    protected $maxAttempts = 5;

    //接收参数（可自定义其他或者多个）
    public $params;

    public function __construct($params)
    {
        // 这里最好是普通数据，不要使用携带 IO 的对象，比如 PDO 对象
        $this->params = $params;
    }

    public function getQueue()
    {
        return $this->queue;
    }
}