<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Tools\Server;
use Hyperf\Redis\Redis;

/**
 * WS 反向索引 stale field 低频清扫（仅 Redis < 7.4 兜底；7.4+ 由 HEXPIRE 每-field TTL 自洁，不跑）。
 *
 * 背景：反向索引是 hash，整-key TTL 会被同维度其他活跃连接不断续命，导致"死而无 onClose"的连接 field 残留。
 * 7.4+ 用 HEXPIRE 每-field TTL 根治；7.4 以下没有该能力，用本清扫兜底。
 *
 * 设计（由 WsServerProcess 每轮调 tick()）：
 *  - **能力门禁**：支持 HEXPIRE 直接 return（不做无谓 SCAN）。
 *  - **leader 化**：反向索引跨服共享，多台都扫=N× 冗余 → 抢一个 leader 锁，只一台扫。
 *  - **限流**：每次只 SCAN 一批（COUNT），cursor 持久化、下轮续扫，避免一次扫爆共享 redis。
 *  - **判活**：field=sv:fd，查正向主绑定 bind:sfd:<sv>:<fd> 是否存在(有自己的 TTL，死连接 60s 后即无)；不存在→HDEL。
 *  - **best-effort**：任何异常吞掉，绝不影响 WsServerProcess 的注册主循环。
 *  - 全程 rawCommand + 手动全前缀，避免 SCAN 返回全 key 与 phpredis 自动前缀来回打架。
 */
class WsBindSweeper
{
    const LOCK_SUFFIX   = 'bind:sweep:leader';   // 逻辑键(自动加 OPT_PREFIX)；值=本机 serverKey
    const CURSOR_SUFFIX = 'bind:sweep:cursor';
    const LOCK_TTL        = 60;                   // leader 租约(秒)
    const SCAN_COUNT      = 500;                  // 每批扫描量
    const SWEEP_INTERVAL  = 60;                   // 两次清扫最小间隔(秒)，与注册循环(15s)解耦——清扫是 best-effort GC，无需高频

    /** 本进程上次执行 sweepBatch 的时间(秒)。每 worker 一份。 */
    private static int $lastSweepAt = 0;

    public static function tick(): void
    {
        try {
            $redis = get_inject_obj(Redis::class);
            if (WsRedisCap::supportsHExpire($redis)) {
                return; // 7.4+：HEXPIRE 已自洁，无需清扫
            }
            //leader 每轮续租(保持锁新鲜、不抖动)；但真正的扫描按 SWEEP_INTERVAL 限频,不跟注册的 15s 走
            if (!self::acquireLeader($redis)) {
                return; // 非 leader，本轮不扫
            }
            $now = time();
            if (($now - self::$lastSweepAt) < self::SWEEP_INTERVAL) {
                return; // 距上次清扫不足间隔,本轮只续租不扫
            }
            self::$lastSweepAt = $now;
            self::sweepBatch($redis);
        } catch (\Throwable $e) {
            // best-effort：忽略，不影响注册主循环
        }
    }

    private static function acquireLeader(Redis $redis): bool
    {
        $me  = self::serverId();
        $key = WsKeys::prefix() . self::LOCK_SUFFIX;
        $cur = $redis->get($key);
        if ($cur === $me) {
            $redis->expire($key, self::LOCK_TTL); // 续租
            return true;
        }
        if ($cur === false || $cur === null || $cur === '') {
            return (bool) $redis->set($key, $me, ['nx', 'ex' => self::LOCK_TTL]);
        }
        return false; // 别的服是 leader
    }

    private static function sweepBatch(Redis $redis): void
    {
        $optPrefix = (string) $redis->getOption(\Redis::OPT_PREFIX);
        $wsFull    = $optPrefix . WsKeys::prefix();        // 如 Server-API:ws:
        $sfdFull   = $wsFull . 'bind:sfd:';                // 正向主绑定(string)全前缀
        $sweepFull = $wsFull . 'bind:sweep:';             // 清扫元数据(leader/cursor,string)全前缀

        $cursorKey = WsKeys::prefix() . self::CURSOR_SUFFIX;
        $cursor    = $redis->get($cursorKey);
        $cursor    = ($cursor === false || $cursor === null) ? '0' : (string) $cursor;

        $res = $redis->rawCommand('SCAN', $cursor, 'MATCH', $wsFull . 'bind:*', 'COUNT', self::SCAN_COUNT);
        if (!is_array($res) || count($res) < 2) {
            return;
        }
        $newCursor = (string) $res[0];
        $keys      = is_array($res[1]) ? $res[1] : [];

        foreach ($keys as $fullKey) {
            //反向索引是 hash;sfd 与 sweep 元数据是 string,前缀排除掉(注:rawCommand TYPE 返回 bool 不可用于判型,改用前缀排除 + is_array 守卫)
            if (strpos($fullKey, $sfdFull) === 0 || strpos($fullKey, $sweepFull) === 0) {
                continue;
            }
            try {
                $flat = $redis->rawCommand('HGETALL', $fullKey); // 反向 hash → [f1,v1,f2,v2,...];万一是 string → WRONGTYPE
                if (!is_array($flat)) {
                    continue;
                }
                $n = count($flat);
                for ($i = 0; $i + 1 < $n; $i += 2) {
                    $field = (string) $flat[$i]; // sv:fd
                    if (!$redis->rawCommand('EXISTS', $sfdFull . $field)) {
                        $redis->rawCommand('HDEL', $fullKey, $field); // 正向已无 → 死连接残留，删
                    }
                }
            } catch (\Throwable $e) {
                // 单 key 异常(如意外 WRONGTYPE)不影响其余
            }
        }

        $redis->set($cursorKey, $newCursor);
    }

    private static function serverId(): string
    {
        return str_replace('.', '_', Server::getIpAddr());
    }
}
