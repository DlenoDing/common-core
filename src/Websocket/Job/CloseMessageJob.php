<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Websocket\Component\WsPushMsgComponent;
use Dleno\CommonCore\Websocket\Component\WsServerComponent;

/**
 * 关闭连接 Job（纯基建，下沉自脚手架）。
 * fds='-1' → 关闭当前 server 全体（枚举注册表）；否则关闭指定 fd 列表。
 */
class CloseMessageJob extends BaseJob
{
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
            $cursor = null;
            while (true) {
                $clients = $wssCpt->getClients($cursor, 100);
                if (empty($clients)) {
                    break;
                }
                foreach ($clients as $client) {
                    try {
                        $pmCpt->close($client);
                    } catch (\Throwable $e) {
                        Logger::businessLog('CLOSE-FD')
                              ->info(array_to_json(['msg' => $e->getMessage()]));
                    }
                }
            }
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
            $this->queue = WsPushMsgComponent::getQueue();
        }
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
