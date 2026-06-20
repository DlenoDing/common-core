<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Tools\Lock\DcsLock;
use Hyperf\Redis\Redis;
use Hyperf\Snowflake\IdGeneratorInterface;

/**
 * WS 反向索引 stale field 低频清扫（仅 Redis < 7.4 兜底；7.4+ 由 HEXPIRE 每-field TTL 自洁，不跑）。
 *
 * 背景：反向索引是 hash，整-key TTL 会被同维度其他活跃连接续命，导致"死而无 onClose"的连接 field 残留。
 * 7.4+ 用 HEXPIRE 每-field TTL 根治；7.4 以下用本清扫兜底。
 *
 * 设计（由 WsServerProcess 每轮调 tick()）：
 *  - **能力门禁**：支持 HEXPIRE 直接 return。
 *  - **leader 化（用现成的 DcsLock）**：抢一次长租锁——DcsLock 原子 SET NX 获取、Lua 原子释放、持有≥10s 自动续约、
 *    加锁协程(进程 handle)结束时 defer 自动释放(关停干净失效切换)。只 leader 那台扫，避免 N× 冗余。
 *  - **注册表 SET 遍历(不全库 SCAN)**：setBind 已把各反向索引 key 登记进 WsKeys::bindIndexKey()；这里只 SSCAN 注册表，
 *    复杂度 O(活跃维度数)而非 O(全库)，吞吐与频率不再敏感；顺手 SREM 掉已空/不存在的反向索引。
 *  - **限流**：SSCAN 每批 COUNT，cursor 持久化续扫；且两次扫描最小间隔 SWEEP_INTERVAL。
 *  - **判活**：field=sv:fd，查正向主绑定是否存在(死连接 60s 后正向即无)→ HDEL。
 *  - **best-effort**：异常吞掉，绝不影响注册主循环。
 */
class WsBindSweeper
{
    const LOCK_KEY        = 'bind:sweep:leader';  // DcsLock 锁 key(逻辑,DcsLock 内部按其 pool 前缀)
    const CURSOR_SUFFIX   = 'bind:sweep:cursor';
    const LOCK_TTL        = 60;                    // leader 租约(秒)，DcsLock 自动续约
    const SCAN_COUNT      = 500;                   // SSCAN 每批(对注册表 SET,远小于全库)
    const SWEEP_INTERVAL  = 60;                    // 两次清扫最小间隔(秒)，与注册循环(15s)解耦

    private static int $lastSweepAt = 0;
    private static bool $isLeader   = false;
    private static string $lockUuid = '';

    public static function tick(): void
    {
        try {
            $redis = get_inject_obj(Redis::class);
            if (WsRedisCap::supportsHExpire($redis)) {
                return; // 7.4+：HEXPIRE 已自洁，无需清扫
            }
            if (!self::ensureLeader()) {
                return; // 非 leader，本轮不扫
            }
            $now = time();
            if (($now - self::$lastSweepAt) < self::SWEEP_INTERVAL) {
                return; // 距上次清扫不足间隔
            }
            self::$lastSweepAt = $now;
            self::sweepBatch($redis);
        } catch (\Throwable $e) {
            // best-effort：忽略，不影响注册主循环
        }
    }

    /**
     * leader 选举：用 DcsLock 抢一次长租锁。抢到即 leader，DcsLock 自动续约保持、进程关停自动释放。
     */
    private static function ensureLeader(): bool
    {
        if (self::$isLeader) {
            return true; // 已是 leader(DcsLock 自动续约中)
        }
        if (self::$lockUuid === '') {
            self::$lockUuid = (string) get_inject_obj(IdGeneratorInterface::class)->generate();
        }
        //timeout=0：抢不到直接返回 false(别的服是 leader)，本轮不扫；抢到则长租
        self::$isLeader = DcsLock::lock(WsKeys::prefix() . self::LOCK_KEY, self::$lockUuid, self::LOCK_TTL, 0);
        return self::$isLeader;
    }

    private static function sweepBatch(Redis $redis): void
    {
        $indexKey  = WsKeys::bindIndexKey();                    // 逻辑 key(原生方法自动加前缀)
        $sfdPrefix = WsKeys::prefix() . 'bind:sfd:';           // 正向主绑定逻辑前缀
        $cursorKey = WsKeys::prefix() . self::CURSOR_SUFFIX;

        $cursor = $redis->get($cursorKey);
        $cursor = ($cursor === false || $cursor === null) ? '0' : (string) $cursor;

        //SSCAN 注册表(只遍历真实反向索引)。rawCommand 不走 OPT_PREFIX,index key 手动补全前缀;
        //成员是各反向索引的"逻辑 key",后续用原生方法(自动前缀)操作。
        $indexFull = (string) $redis->getOption(\Redis::OPT_PREFIX) . $indexKey;
        $res       = $redis->rawCommand('SSCAN', $indexFull, $cursor, 'COUNT', self::SCAN_COUNT);
        if (!is_array($res) || count($res) < 2) {
            return;
        }
        $newCursor = (string) $res[0];
        $members   = is_array($res[1]) ? $res[1] : [];

        foreach ($members as $dimKey) { // dimKey = 逻辑 key，如 ws:bind:account_id:1
            try {
                $fields = $redis->hGetAll($dimKey); // 原生,自动前缀
                if (!is_array($fields) || empty($fields)) {
                    $redis->sRem($indexKey, $dimKey); // 反向 hash 已空/不存在 → 注册表移除
                    continue;
                }
                foreach ($fields as $field => $_serverFd) { // field = sv:fd
                    if (!$redis->exists($sfdPrefix . $field)) {
                        $redis->hDel($dimKey, $field); // 正向主绑定已无 → 死连接残留，删
                    }
                }
            } catch (\Throwable $e) {
                // 单 key 异常不影响其余
            }
        }

        $redis->set($cursorKey, $newCursor);
    }
}
