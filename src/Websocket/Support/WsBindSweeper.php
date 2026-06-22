<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Tools\Lock\DcsLock;
use Hyperf\Redis\Redis;
use Hyperf\Snowflake\IdGeneratorInterface;

use function Hyperf\Config\config;

/**
 * WS 反向索引 stale field 低频清扫（仅 Redis < 7.4 兜底；7.4+ 由 HEXPIRE 每-field TTL 自洁，不跑）。
 *
 * 背景：反向索引是 hash，整-key TTL 会被同维度其他活跃连接续命，导致"死而无 onClose"的连接 field 残留。
 * 7.4+ 用 HEXPIRE 每-field TTL 根治；7.4 以下用本清扫兜底。
 *
 * 设计（由 WsServerProcess 每轮调 tick()）：
 *  - **能力门禁**：支持 HEXPIRE 直接 return。
 *  - **每次执行现拿锁(用现成 DcsLock，非长租)**：每轮新生成 uuid 抢锁(timeout=0)，抢到才扫；
 *    本方法跑在 WsServerProcess 起的后台协程里，sweep 结束协程退出 → DcsLock 的 defer 自动释放锁。
 *    即"用完即放"，不长期占用 leader；用新 uuid 避免上一轮续约 Timer 误删本轮锁。拿不到锁=有人在扫，跳过。
 *  - **协程化(在调用方 WsServerProcess)**：放后台协程跑，清扫再慢也不阻塞主循环的服务器注册续约。
 *  - **注册表 SET 遍历(不全库 SCAN)**：setBind 已把各反向索引 key 登记进 WsKeys::bindIndexKey()；这里只 SSCAN 注册表，
 *    复杂度 O(活跃维度数)而非 O(全库)，吞吐高、对频率不敏感；顺手 SREM 掉已空/不存在的反向索引。
 *  - **限流**：SSCAN 每批 COUNT，cursor 持久化续扫；且两次扫描最小间隔 SWEEP_INTERVAL。
 *  - **判活**：field=sv:fd，查正向主绑定是否存在(死连接 60s 后正向即无)→ HDEL。
 *  - **best-effort**：异常吞掉，绝不影响注册主循环。
 */
class WsBindSweeper
{
    const LOCK_KEY        = 'bind:sweep:lock';    // DcsLock 锁 key(逻辑,DcsLock 内部按其 pool 前缀)
    const CURSOR_SUFFIX   = 'bind:sweep:cursor';
    const LOCK_TTL        = 30;                    // 锁安全 TTL(秒);正常由 defer 提前释放,崩溃则最多 30s 后他人接管

    //业务可配(默认值);按业务量在 config/autoload/websocket.php 的 'sweep' 段调
    const DEFAULT_SCAN_COUNT     = 500;           // SSCAN 每批(对注册表 SET,远小于全库)
    const DEFAULT_SWEEP_INTERVAL = 60;            // 两次清扫最小间隔(秒)，与注册循环(SERVER_REG_LIMIT/2)解耦

    private static int $lastSweepAt = 0;

    /** 每批 SSCAN 量(可配 config('websocket.sweep.scan_count')，默认 500;下限 1 防 0/负致空转) */
    private static function scanCount(): int
    {
        return max(1, (int) config('websocket.sweep.scan_count', self::DEFAULT_SCAN_COUNT));
    }

    /** 两次清扫最小间隔秒数(可配 config('websocket.sweep.interval')，默认 60;下限 1 防 0/负致每 tick 空跑) */
    private static function sweepInterval(): int
    {
        return max(1, (int) config('websocket.sweep.interval', self::DEFAULT_SWEEP_INTERVAL));
    }

    public static function tick(): void
    {
        try {
            $redis = get_inject_obj(Redis::class);
            if (WsRedisCap::supportsHExpire($redis)) {
                return; // 7.4+：HEXPIRE 已自洁，无需清扫
            }
            $now = time();
            if (($now - self::$lastSweepAt) < self::sweepInterval()) {
                return; // 距上次清扫不足间隔
            }
            //每次执行现拿锁(新 uuid)：拿不到=有人在扫,跳过;拿到则本协程结束时 DcsLock 的 defer 自动释放
            $uuid = (string) get_inject_obj(IdGeneratorInterface::class)->generate();
            if (!DcsLock::lock(WsKeys::prefix() . self::LOCK_KEY, $uuid, self::LOCK_TTL, 0)) {
                return;
            }
            self::$lastSweepAt = $now;
            self::sweepBatch($redis);
            //不显式 unlock：DcsLock 已在本协程注册 defer，协程退出即释放
        } catch (\Throwable $e) {
            // best-effort：忽略，不影响注册主循环
        }
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
        $res       = $redis->rawCommand('SSCAN', $indexFull, $cursor, 'COUNT', self::scanCount());
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
