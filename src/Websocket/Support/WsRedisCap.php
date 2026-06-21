<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Hyperf\Redis\Redis;

/**
 * WS 用到的 Redis 能力探测（进程级缓存，探一次）。
 *
 * 目前只探 HEXPIRE（Redis 7.4+ 的 hash field 级过期）。
 * 用"能力探测"而非版本号字符串：托管/分支版(阿里云/ElastiCache/KeyDB/Dragonfly)版本号不可靠；
 * 且统一走 rawCommand,绕开 phpredis 客户端是否实现 hExpire() 方法的差异(部分 phpredis 版本未实现该方法)。
 */
class WsRedisCap
{
    private static ?bool $hExpire = null;

    /**
     * 本 redis(服务端+客户端组合)是否支持 HEXPIRE。
     * **只缓存"确定支持"(true)**：一旦探到支持就钉死、后续零探测；探到"不支持/瞬时失败"则不缓存、下次再探。
     * 理由:probe 走网络,若某次恰逢连接抖动/超时返回 false,缓存 false 会把整个 worker 永久打到 <7.4 慢路径
     * (整-key expire + 注册表 + 清扫),与其它 worker 行为分裂、还会触发活 field 被误续命的风险。
     * 代价:真 <7.4 环境下每次 setBind 多一条 probe(慢路径本就是兜底,可接受),且能在 redis 升级到 7.4 后自动切到快路径。
     */
    public static function supportsHExpire(Redis $redis): bool
    {
        if (self::$hExpire === true) {
            return true;
        }
        $ok = self::probe($redis);
        if ($ok) {
            self::$hExpire = true;
        }
        return $ok;
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
