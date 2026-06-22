<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\Websocket\Support\MessageQueueRouting;
use Dleno\CommonCore\Websocket\Support\WsProcessSwitch;
use Dleno\CommonCore\Websocket\Support\WsQueueConfig;
use Hyperf\Process\Annotation\Process;

/**
 * WS 实时消息消费进程（纯基建，归包锁死）。
 *
 * 消费本机 per-IP 队列（ws:queue:message:<serverKey>）。由 #[Process] 自动注册；
 * 是否启动由 WsProcessSwitch 门禁（ENABLE_WS + local 开关）控制；调优参数（processes/concurrent.limit/
 * max_messages）走 config('websocket.queue')。业务无需也不应继承本类——继承即可篡改核心消费逻辑。
 */
#[Process]
class DcsMessageConsumer extends BaseQueueConsumer
{
    use MessageQueueRouting;//队列名/配置段(实时消息通道,与 PushMessageJob 同一真相)

    public function getQueue()
    {
        //直接赋值覆盖父类默认 'default'(不能用 empty($this->queue) 判断,父类默认非空会致覆盖永不触发)
        $this->queue = self::resolveQueue();
        return $this->queue;
    }

    /**
     * 队列驱动配置：与生产 Job(PushMessageJob)共用同一通道路由（业务调优走 config('websocket.queue')）。
     * @return array
     */
    public function getConfig()
    {
        return WsQueueConfig::resolve($this->getQueue(), self::resolveConfigKey());
    }

    public function isEnable($server): bool
    {
        return WsProcessSwitch::shouldRun();
    }
}
