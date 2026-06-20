<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Hyperf\Redis\Redis;

/**
 * WS 用到的 Redis 能力探测（进程级缓存，探一次）。
 *
 * 目前只探 HEXPIRE（Redis 7.4+ 的 hash field 级过期）。
 * 用"能力探测"而非版本号字符串：托管/分支版(阿里云/ElastiCache/KeyDB/Dragonfly)版本号不可靠；
 * 且统一走 rawCommand,绕开 phpredis 客户端是否实现 hExpire() 方法的差异(本机 phpredis 6.2 就没有该方法)。
 */
class WsRedisCap
{
    private static ?bool $hExpire = null;

    /**
     * 本 redis(服务端+客户端组合)是否支持 HEXPIRE。每 worker 进程探一次后缓存。
     */
    public static function supportsHExpire(Redis $redis): bool
    {
        if (self::$hExpire === null) {
            self::$hExpire = self::probe($redis);
        }
        return self::$hExpire;
    }

    private static function probe(Redis $redis): bool
    {
        try {
            //对探针 key 的不存在 field 执行 HEXPIRE：支持→返回数组(如 [-2]，表示无此 field，且不会创建 key)；
            //不支持(unknown command)→ phpredis 抛异常或返回 false。
            $ret = $redis->rawCommand('HEXPIRE', '__ws_hexpire_probe__', 1, 'FIELDS', 1, 'probe');
            return is_array($ret);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
