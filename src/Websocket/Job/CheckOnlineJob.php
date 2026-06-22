<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Job;

use Dleno\CommonCore\Base\AsyncQueue\BaseJob;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Websocket\Broadcast\CheckFd;
use Dleno\CommonCore\Websocket\Support\ControlQueueRouting;
use Dleno\CommonCore\Websocket\Support\WsKeys;
use Dleno\CommonCore\Websocket\Support\WsQueueConfig;
use Dleno\CommonCore\Websocket\Component\WsServerComponent;
use Hyperf\Redis\Redis;

/**
 * 在线检查 Job（WS 在线判定）。
 * 在目标 server 队列内对 fd 批量核验（CheckFd 三态 true/false/null，null 不带回、交就绪信号+超时兜底），
 * 核验完把结果【一次性随就绪信号带回】:Lua 原子 RPUSH ws:check:ready:<rid> 一条 JSON {sv, pairs:{fd:'1'/'0'}} + PEXPIRE，
 * 由 WsPushMsgComponent::checkRealtimeOnlineByDim 的 BLPOP 即时取用。无 result hash 旁路。
 */
class CheckOnlineJob extends BaseJob
{
    use ControlQueueRouting;//队列名/配置段(控制通道,随 dedicated 开关)

    //接收参数(待核验的 fd 列表)
    /**
     * @var int[]|int
     */
    private $fds;

    /**
     * @var string 请求隔离 rid(由 checkRealtimeOnlineByDim 生成下传),写结果 key 时带上,避免并发互相覆盖
     */
    private $rid;

    public function __construct($fds, $rid = '')
    {
        $this->fds = $fds;
        $this->rid = $rid;
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

        //仅核验指定 fd(实时在线检查已限 unique 维度 + 禁全量;原 fds=-1 全量枚举本机所有连接的路已移除)
        $fds   = is_array($this->fds) ? $this->fds : [$this->fds];
        $pairs = $this->checkFds($fds);

        //核验结果【一次性随就绪信号返回】:Lua 原子 RPUSH {sv,pairs} + PEXPIRE,请求方 BLPOP 直接取用,无 result hash 旁路。
        //(原子化避免 crash 在两命令间留下无 TTL 的 ready key;空 pairs 也照发→"本服务器已核验完但无明确在线 fd",请求方即时唤醒不干等)
        if ($this->rid !== '') {
            $payload  = array_to_json(['sv' => $serverKey, 'pairs' => (object) $pairs]);
            $readyKey = WsKeys::checkReadyKey($this->rid);
            $redis->eval(
                "redis.call('RPUSH', KEYS[1], ARGV[1])\nredis.call('PEXPIRE', KEYS[1], ARGV[2])\nreturn 1",
                [$readyKey, $payload, 10000],
                1
            );
        }

        return true;
    }

    /**
     * 一次批量核验(CheckFd 内部按 CHUNK 分轮、全员应答),返回 fd=>'1'/'0' 供就绪 payload 直接带回。
     * @param int[] $fds
     * @return array<string,string> fd => '1'/'0'(空=本服务器无明确在线/离线结果)
     */
    private function checkFds(array $fds): array
    {
        if (empty($fds) || $this->rid === '') {
            return [];
        }
        $pairs = [];//fd => '1'/'0'
        try {
            $result = CheckFd::check($fds);//[fd => true|false|null]
            foreach ($result as $fd => $online) {
                if ($online === null) {
                    continue;//未知状态(超时未收齐):不写明确结果——就绪信号已保证请求方不会干等,缺失项按离线/重试处理
                }
                $pairs[(string) $fd] = $online ? '1' : '0';
            }
        } catch (\Throwable $e) {
            Logger::businessLog('CHECK-FD')
                  ->info(array_to_json(['msg' => $e->getMessage()]));
        }
        return $pairs;
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
