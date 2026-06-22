<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Hyperf\Server\Server;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * WS 常驻进程（服务器注册 / 实时消息消费）启停门禁。
 *
 * 规则：
 *  1) 总开关 env('ENABLE_WS') 关 → 一律不启（非 WS 项目即便注册了进程也不会运行）。
 *  2) local 环境默认不启；除非显式打开本地开关 config('websocket.local_enable')，
 *     打开后允许本地运行（便于本地联调）。
 *  3) 其余环境 → 启。
 *
 * 设计意图：进程逻辑归包锁死、外部不可继承篡改；唯一对外可控的是"是否启动"——通过 env/config 开关，
 * 而非通过继承 isEnable 来开口子。
 */
class WsProcessSwitch
{
    public static function shouldRun(): bool
    {
        //WS 总开关
        if (!env('ENABLE_WS', false)) {
            return false;
        }
        //local 默认不启；本地开关打开则放行
        if (config('app_env') === 'local' && !config('websocket.local_enable', false)) {
            return false;
        }
        return true;
    }

    /**
     * 是否启用「独立控制队列」(check/close 等控制类 Job 与实时消息分流)。
     *
     * 默认关：CheckOnlineJob/CloseMessageJob 与 PushMessageJob 同走 per-IP 实时消息队列、共用 DcsMessageConsumer。
     * 打开后:控制类 Job 改走独立队列 ws:queue:ctl:<sv>,由独立进程 DcsControlConsumer 消费,
     * 使「在线核验/主动断连」不再与「真实消息下发」抢同一队列/消费协程(消除头阻塞)。
     * 仅控制路由与是否启动独立进程,关时零开销(独立进程不启、Job 落回原队列)。
     */
    public static function dedicatedQueueEnabled(): bool
    {
        return (bool) config('websocket.dedicated_queue.enable', false);
    }

    /**
     * 是否启用了 WebSocket 服务(server.servers 已按 ENABLE_WS 解析,只看实际生效配置)。
     * 供启动前置校验 Listener 共用,避免各处重复扫描 server.servers。
     */
    public static function hasWebSocketServer(): bool
    {
        foreach ((array) config('server.servers', []) as $server) {
            if (($server['type'] ?? null) === Server::SERVER_WEBSOCKET) {
                return true;
            }
        }
        return false;
    }
}
