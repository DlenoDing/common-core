<?php

declare(strict_types=1);

namespace App\AsyncQueue\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\Lock\DcsLock;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Snowflake\IdGeneratorInterface;

class TestJob extends BaseJob
{
    //接收参数（可自定义其他或者多个）
    private $fwid;

    protected $maxAttempts = 20;

    public function __construct($fwid)
    {
        $this->fwid = $fwid;
    }

    /**
     * 消费逻辑（抛错才会认为执行失败）
     * @return bool
     */
    public function handle()
    {
        $lockKey = 'test:' . $this->fwid;
        $uuid    = get_inject_obj(IdGeneratorInterface::class)->generate() . '';
        $lock    = DcsLock::lock($lockKey, $uuid, 15);
        if (!$lock) {
            return false;//未拿到锁，忽略并返回
        }

        //var_dump('执行成功');

        return true;
    }

    public function getQueue()
    {
        $this->queue = Server::getIpAddr();
        $this->queue = 'message:'.str_replace('.', '_', $this->queue);
        return $this->queue;
    }

    /**
     * 自定义 async_queue 对应的$this->queue配置项（动态queue时才需要处理此函数）
     * @return array
     */
    public function getConfig()
    {
        return $this->_getConfig();
    }
}