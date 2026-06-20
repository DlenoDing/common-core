<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use function Hyperf\Config\config;

/**
 * WS 全部 Redis key / 队列命名集中地（生产/消费/清理/Job 四处共用）。
 *
 * 统一前缀可配置：config('websocket.key_prefix')，默认 'ws:'（与历史逐字节一致，BC）。
 * 业务若有自己的 'ws:*' 键担心冲突，改这一个配置即可整体换namespace，无需改代码。
 * 前缀之后的"域后缀"(server:list / bind:sfd: 等)固定不可改，保证协议结构稳定。
 *
 * 注：phpredis OPT_PREFIX（如 "Server-API:"）由扩展自动前置在最外层，本类只管 OPT_PREFIX 之后的逻辑键名。
 * 最终物理键 = <OPT_PREFIX><key_prefix><域后缀...>，例如 Server-API:ws:server:list。
 */
class WsKeys
{
    //统一前缀默认值（可被 config('websocket.key_prefix') 覆盖）
    const DEFAULT_PREFIX = 'ws:';

    //各域后缀（前缀之后的固定部分）
    const SUFFIX_SERVER_LIST = 'server:list';        // 在线服务器列表（hash）
    const SUFFIX_FDS         = 'server:fds:';        // 本服务器 fd 列表（hash）
    const SUFFIX_BIND_SFD    = 'bind:sfd:';          // serverFd 主绑定
    const SUFFIX_BIND_DIM    = 'bind:';              // 维度反向索引前缀（dim=account_id 时即 ...bind:account_id:）
    const SUFFIX_QUEUE       = 'queue:message:';     // per-server 实时消息队列
    const SUFFIX_CHECK       = 'check:online:';      // 在线检查结果

    //时长（秒）—— 与脚手架 WsServerConf 同值（BC）
    const SERVER_REG_LIMIT = 30;                     // 服务器注册频率/有效期基数
    const BIND_CACHE_TIME  = 60;                     // 客户端绑定缓存时间

    //前缀进程级缓存（config 启动期固定，不会运行时变；每 worker 一份，协程安全）
    private static ?string $prefix = null;

    /**
     * 统一 key 前缀（可配置，默认 'ws:'）。
     */
    public static function prefix(): string
    {
        if (self::$prefix === null) {
            self::$prefix = (string) config('websocket.key_prefix', self::DEFAULT_PREFIX);
        }
        return self::$prefix;
    }

    public static function serverListKey(): string
    {
        return self::prefix() . self::SUFFIX_SERVER_LIST;
    }

    public static function fdsKey(string $serverKey): string
    {
        return self::prefix() . self::SUFFIX_FDS . $serverKey;
    }

    public static function bindSfdKey(string $serverKey, $fd): string
    {
        return self::prefix() . self::SUFFIX_BIND_SFD . $serverKey . ':' . $fd;
    }

    /**
     * 维度反向索引 key。dim='account_id' 时 = <prefix>bind:account_id:<v>。
     */
    public static function bindDimKey(string $dim, $value): string
    {
        return self::prefix() . self::SUFFIX_BIND_DIM . $dim . ':' . $value;
    }

    /**
     * 反向索引注册表 key（SET，成员=各反向索引的逻辑 key）。
     * 仅 Redis < 7.4 维护：setBind 登记、WsBindSweeper 只遍历它(不全库 SCAN)。
     */
    public static function bindIndexKey(): string
    {
        return self::prefix() . 'bind:index';
    }

    public static function queueName(string $serverKey): string
    {
        return self::prefix() . self::SUFFIX_QUEUE . $serverKey;
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
        return self::prefix() . self::SUFFIX_CHECK . $serverKey . ':' . $fd;
    }
}
