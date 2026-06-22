<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\Websocket\Support\ControlQueueRouting;
use Dleno\CommonCore\Websocket\Support\WsProcessSwitch;
use Dleno\CommonCore\Websocket\Support\WsQueueConfig;
use Hyperf\Process\Annotation\Process;

/**
 * WS 独立「控制队列」消费进程（纯基建，归包锁死）。
 *
 * 消费本机 per-IP 独立控制队列（ws:queue:ctl:<serverKey>），处理 CheckOnlineJob / CloseMessageJob 等控制类 Job，
 * 使「在线核验 / 主动断连」与「真实消息下发(DcsMessageConsumer)」分流、互不头阻塞。
 *
 * 启停门禁:在 DcsMessageConsumer 的 shouldRun 基础上,**再叠加 dedicated_queue 开关**——
 * 仅当 ENABLE_WS 通过且 config('websocket.dedicated_queue.enable')=true 才启动;关时本进程不启,
 * 控制类 Job 自动回落实时消息队列由 DcsMessageConsumer 消费(零行为变化)。
 *
 * 调优(进程数 processes / 单进程并发 concurrent.limit / max_messages)走 config('websocket.dedicated_queue'),
 * 与实时消息队列 config('websocket.queue') **各自独立**。业务无需也不应继承本类。
 */
#[Process]
class DcsControlConsumer extends BaseQueueConsumer
{
    use ControlQueueRouting;//队列名/配置段(控制通道,与 CheckOnlineJob/CloseMessageJob 同一真相)

    public function getQueue()
    {
        //本进程仅在 dedicated 开关开时启动(isEnable 把关),届时 resolveQueue 即独立控制队列名
        $this->queue = self::resolveQueue();
        return $this->queue;
    }

    /**
     * 队列驱动配置:开关开时读 config('websocket.dedicated_queue') 段,
     * 故 processes / concurrent.limit 与实时消息队列(DcsMessageConsumer)互相独立。
     * @return array
     */
    public function getConfig()
    {
        return WsQueueConfig::resolve($this->getQueue(), self::resolveConfigKey());
    }

    public function isEnable($server): bool
    {
        return WsProcessSwitch::shouldRun() && WsProcessSwitch::dedicatedQueueEnabled();
    }
}
