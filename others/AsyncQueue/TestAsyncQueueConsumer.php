<?php

declare(strict_types=1);

namespace App\AsyncQueue\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\Tools\Server;

/*
 * @Process()
 */
class TestAsyncQueueConsumer extends BaseQueueConsumer
{
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