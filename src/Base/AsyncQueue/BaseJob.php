<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\AsyncQueue\Job;
use Hyperf\Contract\ConfigInterface;

abstract class BaseJob extends Job
{
    /**
     * @var string 与消费进程对应(默认到default)
     */
    protected $queue = null;

    /**
     * 任务执行失败后的重试次数，即最大执行次数为 $maxAttempts+1 次
     *
     * @var int
     */
    protected int $maxAttempts = 5;

    public function getQueue()
    {
        return $this->queue;
    }

    public function setQueue($queue)
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Hyperf 3.2 JobInterface::getPoolName()(原 getQueueName):框架 dispatch() 按它从 DriverFactory 取驱动。
     * 返回本 Job 的 $queue,并在驱动未注册时按 getConfig() 动态注册(与 AsyncQueue::push 同一逻辑),
     * 使 dispatch($job) 与 AsyncQueue::push($job) 落到同一队列。
     */
    public function getPoolName(): string
    {
        $name    = $this->getQueue() ?: 'default';
        $factory = get_inject_obj(BaseDriverFactory::class);
        if (!$factory->has($name)) {
            $config = $this->getConfig();
            if (is_array($config) && !empty($config)) {
                $factory->set($name, $config);
            }
        }
        return $name;
    }

    /**
     * 自定义 async_queue 对应的$this->queue配置项（动态queue时才需要处理此函数）
     * @return array
     */
    public function getConfig()
    {
        return [];
    }

    protected function _getConfig($name = 'default')
    {
        $config = get_inject_obj(ConfigInterface::class)->get('async_queue.' . $name, []);
        if (!empty($config)) {
            //独立队列
            $config['channel'] = $this->getQueue();
        }
        return $config;
    }
}