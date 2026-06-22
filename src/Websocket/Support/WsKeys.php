<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Base\AsyncQueue\BaseDriverFactory;

use function Hyperf\Config\config;

/**
 * WS 全部 Redis key / 队列命名集中地（生产/消费/清理/Job 四处共用）。
 *
 * 统一前缀可配置：config('websocket.key_prefix')，默认 'ws:'。
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
    const SUFFIX_QUEUE_CTL   = 'queue:ctl:';         // per-server 独立控制队列(check/close;dedicated_queue 开关打开时启用)
    const SUFFIX_CHECK_READY = 'check:ready:';       // 在线检查就绪信号(LIST: <rid> → 各服务器核验完 rPush 其 {sv,pairs})
    const SUFFIX_ONLINE      = 'online:';            // 心跳 presence 索引前缀(bucket 化 HASH: ...online:<dim>:<bucket>,field=value→json({sv:{fd:1}}))

    //时长（秒）：服务器注册有效期基数 / 绑定缓存时长
    const SERVER_REG_LIMIT = 30;                     // 服务器注册频率/有效期基数
    const BIND_CACHE_TIME  = 60;                     // 客户端绑定缓存时间

    //心跳 presence 索引的默认 bucket 数(可配 config('websocket.presence_bucket_num'))。
    //小 N=定向查询/全量枚举往返少(≤N 次),但 presence 写只落 N 个 key/slot;连接多/集群大需调大——取舍详见 config('websocket') 注释。
    const PRESENCE_BUCKET_NUM = 4;

    //前缀 / presence bucket 数的进程级缓存（config 启动期固定，不会运行时变；每 worker 一份，协程安全）
    private static ?string $prefix      = null;
    private static ?int    $bucketCount = null;

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
     * 心跳 presence 索引的 bucket key:<prefix>online:<dim>:<bucket>(HASH,field=value→json({sv:{fd:1}}))。
     * bucket = crc32(value) % presence_bucket_num,把"按值散落"的在线判断收敛成"按 bucket 批量 HMGET",
     * 同时分桶避免单 key/单 slot 热点。读写两侧用同一函数算 bucket,保证一致。
     */
    public static function presenceKey(string $dim, $value): string
    {
        //& 0x7fffffff:保证非负(32 位 PHP 下 crc32 可能返回负数→负桶号、全量枚举漏读;Swoole 虽恒 64 位,仍作防御)
        $bucket = (int) ((crc32((string) $value) & 0x7fffffff) % self::presenceBucketCount());
        return self::presenceBucketKey($dim, $bucket);
    }

    /**
     * presence 的 bucket 总数(config('websocket.presence_bucket_num'),默认 4,≤0 回退默认)。
     * 写/读/全量枚举三处共用,保证 bucket 编号一致(中途改值会让旧桶的值漏读,需在无流量窗口改)。
     * 进程级缓存(同 prefix():config 启动期固定;在多条热路径——setBind/refresh/unBind、读循环、全量枚举——被频繁调,免重复读 config)。
     */
    public static function presenceBucketCount(): int
    {
        if (self::$bucketCount === null) {
            $num = (int) config('websocket.presence_bucket_num', self::PRESENCE_BUCKET_NUM);
            self::$bucketCount = $num >= 1 ? $num : self::PRESENCE_BUCKET_NUM;
        }
        return self::$bucketCount;
    }

    /**
     * 按 bucket 序号构造 presence key:<prefix>online:<dim>:<bucket>。供全量枚举遍历 0..count-1。
     */
    public static function presenceBucketKey(string $dim, int $bucket): string
    {
        return self::prefix() . self::SUFFIX_ONLINE . $dim . ':' . $bucket;
    }

    public static function queueName(string $serverKey): string
    {
        return self::prefix() . self::SUFFIX_QUEUE . $serverKey;
    }

    /**
     * Hyperf AsyncQueue 通道的固定 5 子键(供 clearRelServerData 的 unlink 直连删除)。
     * 必须与驱动写入的物理键一致：BaseDriverFactory 给 channel 包了 hash tag,
     * 故此处也用 hashTagChannel(queueName) 拼,既命中真实键、又让 5 子键同 slot(集群下 unlink 不 CROSSSLOT)。
     * @return string[]
     */
    public static function queueSubKeys(string $serverKey): array
    {
        $c = BaseDriverFactory::hashTagChannel(self::queueName($serverKey));
        return [$c . ':waiting', $c . ':reserved', $c . ':delayed', $c . ':failed', $c . ':timeout'];
    }

    /**
     * per-server 独立控制队列名(dedicated_queue 开关打开时,check/close 类 Job 走此队列)。
     */
    public static function dedicatedQueueName(string $serverKey): string
    {
        return self::prefix() . self::SUFFIX_QUEUE_CTL . $serverKey;
    }

    /**
     * 独立控制队列的固定 5 子键(同 queueSubKeys,供 clearRelServerData 下线清理直连 unlink)。
     * @return string[]
     */
    public static function dedicatedQueueSubKeys(string $serverKey): array
    {
        $c = BaseDriverFactory::hashTagChannel(self::dedicatedQueueName($serverKey));
        return [$c . ':waiting', $c . ':reserved', $c . ':delayed', $c . ':failed', $c . ':timeout'];
    }

    /**
     * 在线检查就绪信号 LIST key:<prefix>check:ready:<rid>。消费方核验完某服务器即 rPush 一条 {sv,pairs}(结果直接带回),
     * 请求方 BLPOP 即时唤醒并取用(替代 10ms 轮询 + result hash 旁路);$rid 每次调用唯一做请求隔离;无信号时按超时兜底。
     */
    public static function checkReadyKey(string $rid): string
    {
        return self::prefix() . self::SUFFIX_CHECK_READY . $rid;
    }
}
