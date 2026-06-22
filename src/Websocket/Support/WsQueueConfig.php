<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use function Hyperf\Config\config;

/**
 * WS 队列的驱动配置解析（生产 Job 与消费进程共用同一份，避免两侧不一致）。
 *
 * 设计：消费进程的逻辑归包锁死，但**调优参数对业务开放**——不需要继承消费进程类，
 * 直接在 config/autoload/websocket.php 对应段(默认 'queue';独立控制队列用 'dedicated_queue')设置即可：
 *   - processes      消费进程数
 *   - concurrent.limit 单进程并发消费上限
 *   - max_messages   进程处理多少条后重启
 *   - 其余(driver/pool/retry_seconds/handle_timeout/timeout)继承 async_queue.default
 *
 * 实时消息队列与独立控制队列**各取各的配置段**($overrideConfigKey),故两者的 processes/limit 互相独立。
 * 合并顺序：async_queue.default(基线) ← WS 包内封存默认(concurrent.limit=50) ← config($overrideConfigKey)(业务覆盖)。
 * channel 始终为调用方传入的 per-IP 队列名,不可被覆盖。
 */
class WsQueueConfig
{
    /**
     * @param string $channel          本机 per-IP 队列名(锁死,业务覆盖不可改)
     * @param string $overrideConfigKey 业务覆盖配置段:实时消息='websocket.queue';独立控制队列='websocket.dedicated_queue'
     */
    public static function resolve(string $channel, string $overrideConfigKey = 'websocket.queue'): array
    {
        $base = config('async_queue.default', []);
        //WS 包内默认 concurrent.limit=50（可被对应配置段覆盖）
        $sealed = ['concurrent' => ['limit' => 50]];
        //业务可控覆盖（不需继承，改 config 即可）
        $override = config($overrideConfigKey, []);
        //'enable' 是独立队列的「进程门禁开关」,非队列驱动参数,剔除以免污染 driver 配置
        unset($override['enable']);

        $config = array_replace_recursive($base, $sealed, $override);
        //队列名锁死为传入的 per-IP 队列,业务覆盖不可改
        $config['channel'] = $channel;
        return $config;
    }
}
