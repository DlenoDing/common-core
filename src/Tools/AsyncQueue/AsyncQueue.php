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
        $name   = $job->getQueue() ?: 'default';
        $driver = get_inject_obj(BaseDriverFactory::class);
        if (!$driver->has($name)) {
            $config = $job->getConfig();
            if (is_array($config) && !empty($config)) {
                $driver->set($name, $config);
            }
        }
        return $driver->get($name)
                      ->push($job, $delay);
    }
}
