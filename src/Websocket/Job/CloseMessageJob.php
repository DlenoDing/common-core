<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Websocket\Component\WsPushMsgComponent;
use Dleno\CommonCore\Websocket\Support\ControlQueueRouting;
use Dleno\CommonCore\Websocket\Support\WsQueueConfig;
use Dleno\CommonCore\Websocket\Component\WsServerComponent;

/**
 * 关闭连接 Job（WS 主动断连）。
 * fds='-1' → 关闭当前 server 全体（枚举注册表）；否则关闭指定 fd 列表。
 */
class CloseMessageJob extends BaseJob
{
    use ControlQueueRouting;//队列名/配置段(控制通道,随 dedicated 开关)

    //接收参数（可自定义其他或者多个）
    /**
     * @var int|array
     */
    private $fds;

    //TODO 任务对象不能有大对象的属性（不能用注解）；否则会造成消息体过大

    public function __construct($fds)
    {
        $this->fds = $fds;
    }

    /**
     * 消费逻辑（抛错才会认为执行失败）
     * @return bool
     */
    public function handle()
    {
        $pmCpt = get_inject_obj(WsPushMsgComponent::class);
        if ($this->fds == '-1') {
            $wssCpt = get_inject_obj(WsServerComponent::class);
            //发送给当前服务器的所有人
            //按游标遍历直到 cursor 归 0:不能以「本批为空」为终止——getClients 会过滤过期连接、
            //phpredis 默认 SCAN_NORETRY 也可能返回空批,二者都可能在游标未尽时返回空,以空批终止会漏关连接。
            $cursor = null;
            do {
                $clients = $wssCpt->getClients($cursor, 100);
                foreach ($clients as $client) {
                    try {
                        $pmCpt->close($client);
                    } catch (\Throwable $e) {
                        Logger::businessLog('CLOSE-FD')
                              ->info(array_to_json(['msg' => $e->getMessage()]));
                    }
                }
            } while ((int) $cursor !== 0);
        } else {
            if (!is_array($this->fds)) {
                $this->fds = [$this->fds];
            }
            try {
                foreach ($this->fds as $fd) {
                    $pmCpt->close($fd);
                }
            } catch (\Throwable $e) {
                Logger::businessLog('CLOSE-FD')
                      ->info(array_to_json(['msg' => $e->getMessage()]));
            }
        }

        return true;
    }

    public function getQueue()
    {
        if (empty($this->queue)) {
            $this->queue = self::resolveQueue();
        }
        return $this->queue;
    }

    /**
     * 自定义 async_queue 对应的$this->queue配置项（动态queue时才需要处理此函数）
     * 配置段与 getQueue 的路由同步(由 ControlQueueRouting 决定)。
     * @return array
     */
    public function getConfig()
    {
        return WsQueueConfig::resolve($this->getQueue(), self::resolveConfigKey());
    }
}
