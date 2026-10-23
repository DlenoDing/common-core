<?php

namespace Dleno\CommonCore\Tools\AsyncQueue;

use Dleno\CommonCore\Base\AsyncQueue\BaseDriverFactory;
use Dleno\CommonCore\Base\AsyncQueue\BaseJob;


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
        //getPoolName() 负责解析队列名并按需动态注册驱动(与框架 dispatch() 共用)
        return get_inject_obj(BaseDriverFactory::class)->get($job->getPoolName())
                                                       ->push($job, $delay);
    }
}
