<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\Websocket\Component\WsPushMsgComponent;
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
    /**
     * 本机 per-IP 队列名缓存。
     * 不能用 empty($this->queue) 判断——父类 BaseQueueConsumer::$queue 默认 'default'(非空),
     * 会导致覆盖永不触发、误消费 default 队列。用独立静态变量判断并赋值。
     * @var string|null
     */
    private static ?string $msgQueue = null;

    public function getQueue()
    {
        if (self::$msgQueue === null) {
            self::$msgQueue = WsPushMsgComponent::getQueue();
        }
        $this->queue = self::$msgQueue;
        return $this->queue;
    }

    /**
     * 队列驱动配置：与生产 Job 共用 WsQueueConfig（业务调优走 config('websocket.queue')）。
     * @return array
     */
    public function getConfig()
    {
        return WsQueueConfig::resolve($this->getQueue());
    }

    public function isEnable($server): bool
    {
        return WsProcessSwitch::shouldRun();
    }
}
