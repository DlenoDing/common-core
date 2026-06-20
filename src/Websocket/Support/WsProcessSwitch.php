<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

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
}
