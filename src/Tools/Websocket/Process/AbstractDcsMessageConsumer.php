<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Websocket\Process;

use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\Tools\Websocket\WsPushMsgComponent;

/**
 * WS 实时消息消费进程基类（纯基建，下沉自脚手架）。
 *
 * 消费本机 per-IP 队列（ws:queue:message:<serverKey>，走 WsPushMsgComponent::getQueue）。
 * 业务侧用子类 extends 之，并补上 #[Process] 注解（供 Hyperf 扫描注册）+ isEnable（部署门禁，
 * 如 app_env/ENABLE_WS）。队列解析/并发配置由本基类提供，子类如需可 override getConfig。
 */
abstract class AbstractDcsMessageConsumer extends BaseQueueConsumer
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
     * 自定义 async_queue 对应的$this->queue配置项（动态queue时才需要处理此函数）
     * @return array
     */
    public function getConfig()
    {
        $config = $this->_getConfig();
        $config['concurrent']['limit'] = 50;
        return $config;
    }
}
