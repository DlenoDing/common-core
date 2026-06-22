<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/**
 * WS 业务可控配置（核心逻辑已收敛锁死在 common-core，这里只放对外开放的调优旋钮）。
 *
 * 本文件由 dleno/common-core 安装时自动发布(vendor:publish)到 config/autoload/websocket.php;
 * 已存在则不覆盖,业务可自由修改。
 */
return [
    // WS 所有缓存 key / 队列名的统一前缀（默认 'ws:'）。
    // 业务若有自己的 'ws:*' 键担心冲突，改这里即可整体换 namespace（如 'ws_im:'），无需改代码。
    // 注意：改前缀后历史在线连接/绑定/在途队列的旧键会被孤立，需在无流量窗口切换。
    'key_prefix' => (string) env('WS_KEY_PREFIX', 'ws:'),

    // WS 常驻进程(服务器注册 / 实时消息消费)本地运行开关。
    // 默认 false：local 环境不启这两个进程（保留原 local 判断）；置 true 则允许本地运行，便于联调。
    // 仅影响 local 环境；非 local 环境只看 ENABLE_WS 总开关。
    'local_enable' => (bool) env('WS_LOCAL_ENABLE', false),

    // 在线服务器集合的进程级短缓存(毫秒):在线判断热路径(checkRealtime/HeartbeatOnlineByDim)用它,
    // 避免每次都 HGETALL server:list。≤0 关闭缓存=每次取最新;默认 1000ms(注册有效期 30s 级,1s 量级缓存不影响正确性)。
    'server_set_cache_ms' => (int) env('WS_SERVER_SET_CACHE_MS', 1000),

    // 实时 socket 级在线核验(checkRealtimeOnlineByDim)调优。
    'realtime_online' => [
        // 单次批量上限(禁全量/超大批量);超限抛异常,大批量在线请改用 checkHeartbeatOnlineByDim。下限 1。
        'max'     => (int) env('WS_REALTIME_ONLINE_MAX', 100),
        // 等结果超时(秒):消费方写完即 rPush 就绪信号、请求方 BLPOP 即时唤醒,此超时仅在消费方未响应(队列积压/没跑)时兜底。下限 1。
        'timeout' => (int) env('WS_REALTIME_ONLINE_TIMEOUT', 2),
    ],

    // ── 实时消息队列(DcsMessageConsumer)调优 ──
    // 消费 ws:queue:message:<sv>,承载真实下发(PushMessageJob)。改这里即可,无需继承消费进程类。
    // 未列项继承 async_queue.default(driver/pool/retry_seconds/handle_timeout/timeout)。
    'queue' => [
        // 消费进程数:该队列起几个常驻消费进程并行消费。下发量大 → 调大;默认 1。
        'processes'    => (int) env('WS_CONSUMER_PROCESSES', 1),
        // 单进程并发消费上限:一个消费进程内同时处理多少条消息(协程并发)。默认 50。
        'concurrent'   => ['limit' => (int) env('WS_CONSUMER_LIMIT', 50)],
        // 进程处理多少条消息后自动重启(释放内存/防泄漏);0=不限。
        'max_messages' => (int) env('WS_CONSUMER_MAX_MESSAGES', 0),
    ],

    // ── 独立控制队列(DcsControlConsumer)调优 ──
    // 消费 ws:queue:ctl:<sv>,承载控制类 Job(CheckOnlineJob 在线核验 / CloseMessageJob 主动断连)。
    // 作用:把"在线核验/断连"与"真实消息下发"分流到不同队列+不同消费进程,使控制操作不再头阻塞消息下发(反之亦然)。
    // processes / concurrent.limit 与上面的 'queue' 段**完全独立**,可单独按控制类负载调。
    'dedicated_queue' => [
        // 总开关:是否启用独立控制队列。默认 false(关:控制类 Job 回落实时消息队列、本消费进程不启动,零行为变化)。
        // 置 true 后:控制类 Job 改走 ws:queue:ctl:<sv>,DcsControlConsumer 启动消费(前提仍需 ENABLE_WS 通过)。
        'enable'       => (bool) env('WS_DEDICATED_QUEUE_ENABLE', false),
        // 独立控制队列的消费进程数(与实时消息队列互不影响)。默认 1。
        'processes'    => (int) env('WS_DEDICATED_PROCESSES', 1),
        // 独立控制队列单进程并发消费上限。默认 50。
        'concurrent'   => ['limit' => (int) env('WS_DEDICATED_LIMIT', 50)],
        // 独立控制队列进程处理多少条后自动重启;0=不限。
        'max_messages' => (int) env('WS_DEDICATED_MAX_MESSAGES', 0),
    ],

    // WS 反向索引 stale 清扫调优（仅 Redis < 7.4 才跑；7.4+ 走 HEXPIRE 自洁、本段无效）。
    // 按业务量调：连接/维度多、清理要更快 → 调大 scan_count / 调小 interval；反之调小 scan_count / 调大 interval 降负载。
    'sweep' => [
        'scan_count' => (int) env('WS_SWEEP_SCAN_COUNT', 500),   // 每批 SSCAN 注册表的量
        'interval'   => (int) env('WS_SWEEP_INTERVAL', 60),      // 两次清扫最小间隔(秒)
    ],
];
