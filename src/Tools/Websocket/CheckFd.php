<?php

namespace Dleno\CommonCore\Tools\Websocket;

use Dleno\CommonCore\PipeMessage\Websocket\FdCheckPipeMessage;
use Swoole\Coroutine\Channel;
use Swoole\Server;

class CheckFd
{
    /**
     * 回包等待超时(秒)。全员应答下通常远早于此返回，仅作 Worker 卡死/退出的兜底上限。
     */
    const CHECK_TIMEOUT = 0.5;

    /**
     * 单条管道消息的序列化字节上限。
     * 自定义进程回包走 exportSocket(SOCK_DGRAM),Hyperf AbstractProcess 用 recv(65535) 接收,
     * 超过 65535 字节的数据报会被静默截断导致 unserialize 失败/丢包;故取 60000 留足余量。
     * 请求/回包均按此上限【按字节】分块,与 fd 的数量/量级无关,实测安全。
     */
    const MAX_MSG_BYTES = 60000;

    /**
     * getClientList 枚举分页大小。
     */
    const ENUM_PAGE = 100;

    /**
     * deliver 向 Channel push 的超时(秒)，避免早退/超时后阻塞监听协程。
     */
    const PUSH_TIMEOUT = 0.05;

    /**
     * 等待中的检查请求：rid => Channel
     * 由本进程的 OnPipeMessageListener 收到 TYPE_CHECK_RETURN 后 push 唤醒。
     * @var Channel[]
     */
    public static $channels = [];

    /**
     * 进程级自增请求号(map 仅本进程内查找，无需跨进程唯一)
     * @var int
     */
    private static $ridSeq = 0;

    /**
     * 进程级缓存：Swoole Server 单例(进程生命周期内不变)。
     */
    private static $server = null;

    /**
     * 检查 fd 是否为活跃连接。支持单个 / 多个 / 全量。返回统一为 [fd => true|false|null]。
     *
     * @param int|int[]|string $fds 单个 fd；fd 数组；或 -1/'-1' 表示本服务器全量
     * @return array
     *   - 单个 int / int[] → [fd => true|false|null]
     *       true =在线；
     *       false=确定离线(已收齐所有 Worker 应答);
     *       null =未知(超时未收齐,无法确定)——外部应与 false 区别对待,勿当离线。
     *   - -1               → [fd => true]（仅含在线 fd;无候选全集,无法表达 null）
     *   - 空入参           → []
     */
    public static function check($fds = []): array
    {
        $isAll = ($fds === -1 || $fds === '-1');

        $candidates = [];
        if (!$isAll) {
            $arr = is_array($fds) ? $fds : [$fds];
            foreach ($arr as $v) {
                $candidates[(int)$v] = true;//去重
            }
            $candidates = array_keys($candidates);
            if (empty($candidates)) {
                return [];
            }
        }

        //WS 服务强制 SWOOLE_BASE(启动前置校验 WsServerModeCheckListener 已拦截非 BASE)
        return self::queryBase($isAll, $candidates);
    }

    /**
     * SWOOLE_BASE：跨 Worker 批量询问。
     * 返回 [fd => true|false|null]（list）或 [fd => true]（all）。
     */
    private static function queryBase(bool $isAll, array $candidates): array
    {
        $server     = self::server();
        $workerNum  = (int)($server->setting['worker_num'] ?? 0);
        $taskNum    = (int)($server->setting['task_worker_num'] ?? 0);
        $selfWidRaw = $server->worker_id;
        //事件 Worker：持有 WS 连接、可被 sendMessage 精准回发
        $isEventWorker = !$server->taskworker && $selfWidRaw >= 0 && $selfWidRaw < $workerNum;
        //归一化为可寻址 worker_id(越界=自定义进程→-1，回发走广播)
        $selfWid = ($selfWidRaw >= 0 && $selfWidRaw < $workerNum + $taskNum) ? $selfWidRaw : -1;

        if ($isAll) {
            $online = $isEventWorker ? self::localActives($server) : [];
            $res    = self::queryRound($server, $workerNum, $selfWid, $isEventWorker, FdCheckPipeMessage::MODE_ALL, [], -1);
            $status = [];
            foreach (array_unique(array_merge($online, $res['online'])) as $fd) {
                $status[$fd] = true;
            }
            return $status;
        }

        //list：事件 Worker 先本地自查,其余 fd 广播询问
        $status    = [];
        $remaining = [];
        if ($isEventWorker) {
            foreach ($candidates as $fd) {
                if (self::isFdActive($server, $fd)) {
                    $status[$fd] = true;
                } else {
                    $remaining[] = $fd;//本地非活跃,可能在其它 Worker,需广播
                }
            }
        } else {
            $remaining = $candidates;
        }

        //按【字节】分块逐块询问;依据本块是否"收齐"决定未命中 fd 是 false(确定离线)还是 null(未知)
        foreach (self::chunkByBytes($remaining) as $chunk) {
            $res       = self::queryRound($server, $workerNum, $selfWid, $isEventWorker, FdCheckPipeMessage::MODE_LIST, $chunk, count($chunk));
            $onlineSet = array_fill_keys($res['online'], true);
            foreach ($chunk as $fd) {
                if (isset($onlineSet[$fd])) {
                    $status[$fd] = true;
                } elseif ($res['complete']) {
                    $status[$fd] = false;//已收齐所有 Worker 应答 → 确定离线
                } else {
                    $status[$fd] = null; //超时未收齐 → 未知
                }
            }
        }
        return $status;
    }

    /**
     * 一个 rid 的一次往返：广播请求 → 全员应答 → 求在线并集。
     *
     * @param int $need 需命中的候选数(list 模式)；-1 表示 all 模式(不早退、收齐为止)
     * @return array ['online' => int[], 'complete' => bool]
     *   complete=false 表示超时未收齐(有 Worker 未应答),未命中 fd 应视为"未知"(null)。
     *   注：已命中的在线 fd 始终权威可信;逐条累加,某 Worker 不应答不会丢失其它 Worker 已到的数据。
     */
    private static function queryRound($server, int $workerNum, int $selfWid, bool $isEventWorker, string $mode, array $fdsChunk, int $need): array
    {
        $rid = ++self::$ridSeq;
        //all 模式回包按字节分块后块数可能很多,Channel 容量调大留足突发余量;
        //list 模式回包受请求块约束(回包 ⊆ 请求),小容量即可。
        $cap     = ($mode === FdCheckPipeMessage::MODE_ALL) ? max($workerNum * 16, 512) : max($workerNum * 2, 64);
        $channel = new Channel($cap);
        self::$channels[$rid] = $channel;
        try {
            $msg = new FdCheckPipeMessage(FdCheckPipeMessage::TYPE_CHECK_TO, [
                'mode'      => $mode,
                'fds'       => $fdsChunk,
                'rid'       => $rid,
                'sworkerId' => $selfWid,
            ]);

            $pending = [];//wid => true
            for ($wid = 0; $wid < $workerNum; ++$wid) {
                if ($isEventWorker && $wid === $selfWid) {
                    continue;//已本地自查，无需发给自己
                }
                try {
                    $server->sendMessage($msg, $wid);
                    $pending[$wid] = true;
                } catch (\Throwable $e) {
                    //单个 worker 不可达不影响对其余 worker 的询问
                }
            }
            if (empty($pending)) {
                //无接收者:本地已是全部信息,视为已收齐
                return ['online' => [], 'complete' => true];
            }

            $onlineSet = [];//fd => true
            $budget    = self::CHECK_TIMEOUT;
            $complete  = false;
            while (!empty($pending) && $budget > 0) {
                $t0    = microtime(true);
                $reply = $channel->pop($budget);
                $budget -= (microtime(true) - $t0);
                if ($reply === false) {
                    break;//兜底超时:complete 保持 false,未应答 Worker 的 fd 归为未知
                }
                if (!empty($reply->fds) && is_array($reply->fds)) {
                    foreach ($reply->fds as $fd) {
                        $onlineSet[(int)$fd] = true;
                    }
                }
                if (!empty($reply->last)) {
                    unset($pending[$reply->fromWid]);
                }
                //早退：list 模式所有候选已命中(全部在线,无歧义,可判已收齐)
                if ($need >= 0 && count($onlineSet) >= $need) {
                    $complete = true;
                    break;
                }
            }
            if (empty($pending)) {
                $complete = true;//已收齐所有 Worker 的 last
            }
            return ['online' => array_keys($onlineSet), 'complete' => $complete];
        } finally {
            unset(self::$channels[$rid]);
        }
    }

    /**
     * 收到回包：投递给对应批次的等待协程。
     * 由 OnPipeMessageListener 在 TYPE_CHECK_RETURN 时调用。
     * @param FdCheckPipeMessage $reply
     */
    public static function deliver($reply): void
    {
        $rid = $reply->rid ?? null;
        if ($rid === null) {
            return;
        }
        $channel = self::$channels[$rid] ?? null;
        if ($channel !== null) {
            //带超时，避免早退/超时后阻塞监听协程
            $channel->push($reply, self::PUSH_TIMEOUT);
        }
    }

    /**
     * 将 fd 列表按【序列化字节预算】分块,保证每块封装成消息后不超过 MAX_MSG_BYTES。
     * 与 fd 的数量/量级无关:fd 越大、每个占用字节越多,单块自动装得越少。
     * @param int[] $fds
     * @return int[][]
     */
    public static function chunkByBytes(array $fds): array
    {
        $chunks   = [];
        $cur      = [];
        $base     = 200;//消息外壳(type/mode/rid/spid/sworkerId/fromWid/last + serialize 结构)预留
        $curBytes = $base;
        foreach ($fds as $fd) {
            //保守估计单元素序列化开销:`i:KEY;i:VALUE;` 量级,strlen(值)+固定开销
            $est = strlen((string)$fd) + 14;
            if (!empty($cur) && $curBytes + $est > self::MAX_MSG_BYTES) {
                $chunks[] = $cur;
                $cur      = [];
                $curBytes = $base;
            }
            $cur[]    = $fd;
            $curBytes += $est;
        }
        if (!empty($cur)) {
            $chunks[] = $cur;
        }
        return $chunks;
    }

    /**
     * 枚举“当前进程”持有的全部活跃 WS fd。
     * BASE 模式下只返回本 Worker 连接；PROCESS 模式下连接表共享返回全部。
     * @param \Swoole\Server $server
     * @return int[]
     */
    public static function localActives($server): array
    {
        $result = [];
        $start  = 0;
        while (true) {
            $list = $server->getClientList($start, self::ENUM_PAGE);
            if ($list === false || empty($list)) {
                break;
            }
            foreach ($list as $fd) {
                if (self::isFdActive($server, $fd)) {
                    $result[] = (int)$fd;
                }
                $start = $fd;
            }
            if (count($list) < self::ENUM_PAGE) {
                break;
            }
        }
        return $result;
    }

    /**
     * 判断某 fd 在“当前进程”是否为活跃的 WebSocket 连接。
     * BASE 模式下每个进程只认自己持有的连接，非本进程持有的 fd 返回 false。
     * check() 自查与 OnPipeMessageListener 应答共用此谓词，避免判定口径漂移。
     * @param \Swoole\Server $server
     * @param int $fd
     * @return bool
     */
    public static function isFdActive($server, $fd): bool
    {
        try {
            //getClientInfo 为 connection_info 的非过时别名(Swoole 5/6),返回结构一致
            $info = $server->getClientInfo($fd);
            return (is_array($info) && ($info['websocket_status'] ?? null) === WEBSOCKET_STATUS_ACTIVE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 进程级缓存的 Swoole Server 单例(每进程各自缓存自身的 server 对象/worker_id)。
     * @return \Swoole\Server
     */
    private static function server()
    {
        if (self::$server === null) {
            self::$server = get_inject_obj(Server::class);
        }
        return self::$server;
    }
}
