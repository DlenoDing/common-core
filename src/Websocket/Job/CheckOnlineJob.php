<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Websocket\Broadcast\CheckFd;
use Dleno\CommonCore\Websocket\Component\WsPushMsgComponent;
use Dleno\CommonCore\Websocket\Support\WsQueueConfig;
use Dleno\CommonCore\Websocket\Component\WsServerComponent;
use Hyperf\Redis\Redis;

/**
 * 在线检查 Job（WS 在线判定）。
 * 在目标 server 队列内对 fd 批量核验（CheckFd 三态 true/false/null，null 不写、交超时重试），
 * 命中结果写 ws:check:online:<sv>:<fd>（TTL 5s），由 WsPushMsgComponent::checkClientOnline 轮询聚合。
 */
class CheckOnlineJob extends BaseJob
{
    //接收参数（可自定义其他或者多个）
    /**
     * @var int
     */
    private $fds;

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
        $wssCpt    = get_inject_obj(WsServerComponent::class);
        $serverKey = $wssCpt->getServerKey();
        $redis     = get_inject_obj(Redis::class);

        if ($this->fds === '-1' || $this->fds === -1) {
            //检查当前服务器的所有人:先汇总全部记录的客户端,再一次批量核验
            $all    = [];
            $cursor = null;
            while (true) {
                $clients = $wssCpt->getClients($cursor, 100);
                if (empty($clients)) {
                    break;
                }
                foreach ($clients as $fd) {
                    $all[] = (int)$fd;
                }
            }
            $this->checkAndSet($redis, $serverKey, $all);
        } else {
            $fds = is_array($this->fds) ? $this->fds : [$this->fds];
            $this->checkAndSet($redis, $serverKey, $fds);
        }

        return true;
    }

    /**
     * 一次批量检查并写回结果(内部按 CHUNK 分轮、全员应答)。
     * @param int[] $fds
     */
    private function checkAndSet(Redis $redis, $serverKey, array $fds): void
    {
        if (empty($fds)) {
            return;
        }
        try {
            $result = CheckFd::check($fds);//[fd => true|false|null]
            foreach ($result as $fd => $online) {
                if ($online === null) {
                    continue;//未知状态(超时未收齐):不写明确结果,交由上层超时/重试,避免误判离线
                }
                $this->setOnline($redis, $serverKey, $fd, $online);
            }
        } catch (\Throwable $e) {
            Logger::businessLog('CHECK-FD')
                  ->info(array_to_json(['msg' => $e->getMessage()]));
        }
    }

    private function setOnline(Redis $redis, $serverKey, $fd, $online)
    {
        $checkKey = WsPushMsgComponent::getCheckKey($serverKey, $fd);
        $redis->set($checkKey, strval($online ? 1 : 0), 5);
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
        return WsQueueConfig::resolve($this->getQueue());
    }
}
