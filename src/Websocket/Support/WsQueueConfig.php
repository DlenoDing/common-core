<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use function Hyperf\Config\config;

/**
 * WS 实时消息队列的驱动配置解析（生产 Job 与消费进程共用同一份，避免两侧不一致）。
 *
 * 设计：消费进程的逻辑归包锁死，但**调优参数对业务开放**——不需要继承消费进程类，
 * 直接在 config/autoload/websocket.php 的 'queue' 段设置即可：
 *   - processes      消费进程数
 *   - concurrent.limit 单进程并发消费上限
 *   - max_messages   进程处理多少条后重启
 *   - 其余(driver/pool/retry_seconds/handle_timeout/timeout)继承 async_queue.default
 *
 * 合并顺序：async_queue.default(基线) ← WS 包内封存默认(concurrent.limit=50) ← config('websocket.queue')(业务覆盖)。
 * channel 始终为本机 per-IP 队列名(由调用方传入)，不可被覆盖。
 */
class WsQueueConfig
{
    public static function resolve(string $channel): array
    {
        $base = config('async_queue.default', []);
        //WS 包内封存默认（原消费进程里硬编码的 concurrent.limit=50）
        $sealed = ['concurrent' => ['limit' => 50]];
        //业务可控覆盖（不需继承，改 config 即可）
        $override = config('websocket.queue', []);

        $config = array_replace_recursive($base, $sealed, $override);
        //队列名锁死为 per-IP 实时队列，业务覆盖不可改
        $config['channel'] = $channel;
        return $config;
    }
}
