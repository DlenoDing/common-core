<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Websocket;

use Dleno\CommonCore\PipeMessage\Websocket\WsBroadcastPipeMessage;
use Hyperf\WebSocketServer\Collector\FdCollector;
use Swoole\Server;

use function Hyperf\Support\env;

/**
 * WS 广播（向当前服务器全体在线连接推送同一消息）。
 *
 * 设计要点（详见 WS广播优化方案(H8).md）：
 *  - 不逐 fd 经 Sender 扇出（BASE 下那是 N×(W-1) 条管道写）；
 *  - BASE：给每个事件 worker 投一条信号，由各 worker 用本地 push 推送自己名下连接（O(W) IPC）；
 *  - PROCESS：连接表共享，单进程枚举全量直推（无需 PipeMessage）；
 *  - 复用 CheckFd 的本地活跃 fd 枚举/过滤（已 e2e 验证）。
 */
class WsBroadcast
{
    /**
     * 每推送多少个连接让出一次，避免大广播长占 worker reactor。
     * 注：本版 Swoole 的 \Swoole\Coroutine::sleep(0) 不让出，故用极小正值 1ms。
     */
    const YIELD_EVERY = 2000;

    private static $server = null;

    private static function server()
    {
        if (self::$server === null) {
            self::$server = get_inject_obj(Server::class);
        }
        return self::$server;
    }

    /**
     * 向当前服务器全体在线连接广播 payload。
     * @param string $payload 已序列化的帧文本
     * @param int $nfd 需排除的 fd（0=不排除）
     */
    public static function toAll(string $payload, int $nfd = 0): void
    {
        $opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT;
        $flags  = SWOOLE_WEBSOCKET_FLAG_FIN
            | (env('WEBSOCKET_COMPRESSION', false) ? SWOOLE_WEBSOCKET_FLAG_COMPRESS : 0);
        $server = self::server();
        $msg    = new WsBroadcastPipeMessage($payload, $nfd, $opcode, $flags);

        // WS 服务强制 SWOOLE_BASE（启动前置校验 WsServerModeCheckListener 已拦截非 BASE）：
        // 每个事件 worker 私有连接 → 信号广播给所有事件 worker，各推本地
        $workerNum  = (int)($server->setting['worker_num'] ?? 0);
        $taskNum    = (int)($server->setting['task_worker_num'] ?? 0);
        $selfWidRaw = $server->worker_id;
        // 事件 worker：持有连接、可被精准回发；自定义进程/越界 → 归一化 -1
        $isEventWorker = !$server->taskworker && $selfWidRaw >= 0 && $selfWidRaw < $workerNum;
        $selfWid = ($selfWidRaw >= 0 && $selfWidRaw < $workerNum + $taskNum) ? $selfWidRaw : -1;

        if ($isEventWorker) {
            self::pushLocal($server, $msg);// 从事件 worker 触发：本地直接推
        }
        for ($wid = 0; $wid < $workerNum; ++$wid) {
            if ($isEventWorker && $wid === $selfWid) {
                continue;// 已本地推，跳过自己（防 self-send）
            }
            try {
                $server->sendMessage($msg, $wid);
            } catch (\Throwable $e) {
                // 单个 worker 不可达不影响其余 worker
            }
        }
    }

    /**
     * 在当前事件 worker 内，推送给本地全部活跃连接（供 toAll() 自身分支与 OnPipeMessageListener 复用）。
     */
    public static function pushLocal($server, WsBroadcastPipeMessage $msg): void
    {
        $nfd = $msg->nfd;
        // 直接复用 Hyperf 框架已维护的 per-worker fd 集合 FdCollector
        //（框架在 onHandShake 时 set、onClose 时 del，本 worker 私有，零额外维护、零 syscall 枚举）；
        // 极端为空时回落 CheckFd::localActives（安全降级）
        $fds = array_keys(FdCollector::list());
        if (empty($fds)) {
            $fds = CheckFd::localActives($server);
        }
        $i = 0;
        foreach ($fds as $fd) {
            if ($fd === $nfd) {
                continue;
            }
            try {
                $server->push($fd, $msg->payload, $msg->opcode, $msg->flags);
            } catch (\Throwable $e) {
                // 个别 fd 刚断开等，忽略不影响其余
            }
            if ((++$i % self::YIELD_EVERY) === 0) {
                \Swoole\Coroutine::sleep(0.001);// 协作让出，防长占 reactor
            }
        }
    }
}
