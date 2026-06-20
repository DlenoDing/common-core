<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Websocket;

/**
 * WS 全部 Redis key / 队列命名集中地（生产/消费/清理/Job 四处共用）。
 *
 * BC 字节级对齐：各方法产出必须 == 脚手架原 WsServerConf/WsPushMsgComponent 常量拼接，逐字节一致，
 * 否则在线连接/绑定/在途 job 被孤立。注：phpredis OPT_PREFIX（如 "Server-API:"）由扩展自动前置，
 * 本类只管逻辑键名（不含该前缀）。
 */
class WsKeys
{
    const SERVER_LIST     = 'ws:server:list';      // 在线服务器列表（hash）
    const FDS_PREFIX      = 'ws:server:fds:';       // 本服务器 fd 列表（hash）
    const BIND_SFD_PREFIX = 'ws:bind:sfd:';         // serverFd 主绑定
    const BIND_DIM_PREFIX = 'ws:bind:';             // 维度反向索引前缀（dim=account_id 时即原 ws:bind:account_id:）
    const QUEUE_PREFIX    = 'ws:queue:message:';     // per-server 实时消息队列
    const CHECK_PREFIX    = 'ws:check:online:';      // 在线检查结果

    //时长（秒）—— 与脚手架 WsServerConf 同值（BC）
    const SERVER_REG_LIMIT = 30;                     // 服务器注册频率/有效期基数
    const BIND_CACHE_TIME  = 60;                     // 客户端绑定缓存时间

    public static function serverListKey(): string
    {
        return self::SERVER_LIST;
    }

    public static function fdsKey(string $serverKey): string
    {
        return self::FDS_PREFIX . $serverKey;
    }

    public static function bindSfdKey(string $serverKey, $fd): string
    {
        return self::BIND_SFD_PREFIX . $serverKey . ':' . $fd;
    }

    /**
     * 维度反向索引 key。dim='account_id' 时 = 原 ws:bind:account_id:<v>（BC 天然兼容）。
     */
    public static function bindDimKey(string $dim, $value): string
    {
        return self::BIND_DIM_PREFIX . $dim . ':' . $value;
    }

    public static function queueName(string $serverKey): string
    {
        return self::QUEUE_PREFIX . $serverKey;
    }

    /**
     * Hyperf AsyncQueue 通道的固定 5 子键。
     * @return string[]
     */
    public static function queueSubKeys(string $serverKey): array
    {
        $c = self::queueName($serverKey);
        return [$c . ':waiting', $c . ':reserved', $c . ':delayed', $c . ':failed', $c . ':timeout'];
    }

    public static function checkKey(string $serverKey, $fd): string
    {
        return self::CHECK_PREFIX . $serverKey . ':' . $fd;
    }
}
