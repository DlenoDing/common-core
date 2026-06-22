<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Websocket\Broadcast\WsBroadcast;
use Dleno\CommonCore\Websocket\Component\WsPushMsgComponent;
use Dleno\CommonCore\Websocket\Support\MessageQueueRouting;
use Dleno\CommonCore\Websocket\Support\WsQueueConfig;

/**
 * 推送消息 Job（WS 出站投递）。
 * 出站封套 {m:cmd, d:data} 在 parseCmdMessage 锁死（协议归包）。
 * 无 fd → WsBroadcast::toAll 本机全员广播；有 fd → 本机定向 Sender。
 */
class PushMessageJob extends BaseJob
{
    use MessageQueueRouting;//队列名/配置段(实时消息通道)

    //接收参数（可自定义其他或者多个）
    /**
     * @var int
     */
    private $cmd;
    /**
     * @var array
     */
    private $data;

    //注意:任务对象不能持有大对象属性（不能用注解注入），否则会放大队列消息体。

    public function __construct($cmd, $data = [])
    {
        $this->cmd  = $cmd;
        $this->data = $data;
    }

    /**
     * 消费逻辑（抛错才会认为执行失败）
     * @return bool
     */
    public function handle()
    {
        $fd  = $this->data['fd'] ?? 0;
        $nfd = $this->data['nfd'] ?? 0;
        unset($this->data['fd'], $this->data['nfd']);
        $pmCpt   = get_inject_obj(WsPushMsgComponent::class);
        $message = $this->parseCmdMessage();
        if (empty($fd)) {
            //发送给当前服务器的所有人:每事件 worker 一条信号、各自推本地连接(O(W) IPC)
            WsBroadcast::toAll($message, (int)$nfd);
        } else {
            //发送给当前服务器指定的人
            try {
                $pmCpt->send($fd, $message);
            } catch (\Throwable $e) {
                Logger::businessLog('PUSH-FD')
                      ->info(array_to_json(['msg' => $e->getMessage()]));
            }
        }
        return true;
    }

    private function parseCmdMessage()
    {
        return array_to_json(
            [
                'm' => $this->cmd,
                'd' => $this->data,
            ]
        );
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
     * @return array
     */
    public function getConfig()
    {
        return WsQueueConfig::resolve($this->getQueue(), self::resolveConfigKey());
    }
}
