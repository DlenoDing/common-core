<?php

namespace Dleno\CommonCore\Tools\AsyncQueue;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;


class AsyncQueue
{
    /**
     * 发送AQ消息
     * @param BaseJob $job
     * @param int $delay
     * @return bool
     */
    public static function push(BaseJob $job, int $delay = 0)
    {
        $name = $job->getQueue() ?: 'default';
        return get_inject_obj(DriverFactory::class)
            ->get($name)
            ->push($job, $delay);
    }
}
