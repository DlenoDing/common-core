<?php

declare (strict_types=1);

namespace Dleno\CommonCore\Websocket\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Websocket\Support\WsKeys;
use Dleno\CommonCore\Websocket\Job\CheckOnlineJob;
use Dleno\CommonCore\Websocket\Job\CloseMessageJob;
use Dleno\CommonCore\Websocket\Job\PushMessageJob;
use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;
use Dleno\CommonCore\Tools\AsyncQueue\AsyncQueue;
use Hyperf\Coroutine\Parallel;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Hyperf\WebSocketServer\Sender;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * WS 消息推送/在线检查/关闭 编排器（纯基建）。
 *
 * - send/close：本机 Sender 直推/断连（压缩开关读 env，无法走配置中心）
 * - pushPubMessage：广播；pushToDimMessage：按业务维度(WsBindStrategy)反向索引定向，分发 PushMessageJob 到各 server 的 per-IP 队列
 * - checkRealtimeOnlineByDim：实时 socket 级核验（仅 uniqueDimensions 单连接维度、批量有上限、禁全量）——派 CheckOnlineJob 到目标 server 队列 + 轮询 check key 聚合
 * - checkHeartbeatOnlineByDim：心跳级在线判断（读 presence 索引 ws:online:<dim>:<bucket>，按 bucket 批量 HMGET，廉价、适合大批量；精度为心跳/TTL 粒度；presence 由绑定写路径维护）
 * - closeClient：派 CloseMessageJob
 *
 * 维度名(account_id / device …)一律由调用方(业务)传入，本组件不绑定任何具体维度。
 *
 * 队列名/在线检查 key 全部走 WsKeys（ws:queue:message:/ws:check:ready:）。
 * 出站协议封套 {m:cmd, d:data} 在 PushMessageJob 内构造并锁死（归包，业务改不到）；
 * cmd 取值由业务自定义。业务侧用空子类 extends 之即可。
 */
class WsPushMsgComponent extends BaseCoreComponent
{
    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected Sender $sender;

    /**
     * 给当前服务器的指定FD发送消息
     * @param $fd
     * @param $data
     */
    public function send($fd, $data)
    {
        //这个配置无法通过配置中心来设置
        if (!env('WEBSOCKET_COMPRESSION', false)) {
            $this->sender->push(intval($fd), $data);
        } else {
            $this->sender->push(
                intval($fd),
                $data,
                SWOOLE_WEBSOCKET_OPCODE_TEXT,
                SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
            );
        }
    }

    /**
     * 关闭指定连接
     * @param $fd
     */
    public function close($fd)
    {
        $this->sender->disconnect(intval($fd));
    }

    //实时核验批量上限:每值都要派 job + 等结果,代价高;限批量、禁全量/超大批量,防压垮单消费进程。默认值,可经 config('websocket.realtime_online.max') 调。
    private const REALTIME_ONLINE_MAX = 100;

    //实时核验等待超时(秒):消费方把结果随 ready payload rPush,请求方 BLPOP 即时唤醒;此超时只在消费方未响应(队列积压/没跑)时兜底。默认值,可经 config('websocket.realtime_online.timeout') 调。
    private const REALTIME_ONLINE_TIMEOUT = 2;

    /**
     * 【实时 socket 级】在线核验:把所有待查值在「同一服务器」上的全部 fd **汇成一批**,
     * 每服务器只下一个 CheckOnlineJob(一次 getClientInfo 批量核验)、结果随 ready payload 直接带回;
     * 请求方 BLPOP 即时唤醒、收齐各服务器结果后,再按值归集(该值任一连接在线即在线)。
     * —— 故无论查几个用户,每台服务器都只一次跨 worker 核验 + 一条 ready payload(不再每用户查一次)。
     * 精确到当下,但代价仍高(异步 job + 跨 worker 核验 + 可能 2s 超时尾),故仅限:
     *   - **维度必须是 uniqueDimensions(单连接维度)**——非 unique 维度每值可能多连接、fan-out 不可控,改用 checkHeartbeatOnlineByDim;
     *   - **批量有上限**(REALTIME_ONLINE_MAX,config('websocket.realtime_online.max')),超限抛异常;禁全量。
     * @param string $dim    维度名(须在 WsBindStrategy::uniqueDimensions() 内)
     * @param array  $values 维度值列表(数量 ≤ 上限)
     * @return array value => bool(任一连接实时在线即 true)
     */
    public function checkRealtimeOnlineByDim(string $dim, array $values, int $concurrent = 100)
    {
        //仅允许 unique(单连接)维度:非 unique 维度 fan-out 大、实时核验代价不可控
        $unique = get_inject_obj(WsBindStrategyInterface::class)->uniqueDimensions();
        if (!in_array($dim, $unique, true)) {
            throw new \InvalidArgumentException(
                "checkRealtimeOnlineByDim 仅支持 uniqueDimensions 维度,[{$dim}] 非 unique;多连接维度的在线判断请用 checkHeartbeatOnlineByDim"
            );
        }
        //先去重(重复值不应误触发上限),空入参直接返回
        $values = array_values(array_unique($values));
        if ($values === []) {
            return [];
        }
        //批量上限(禁全量/超大批量);下限 clamp 1,防 env 配 0/负导致恒抛异常
        $max = max(1, (int) config('websocket.realtime_online.max', self::REALTIME_ONLINE_MAX));
        if (count($values) > $max) {
            throw new \InvalidArgumentException(
                "checkRealtimeOnlineByDim 批量上限 {$max},传入 " . count($values) . ";大批量/全量在线请用 checkHeartbeatOnlineByDim"
            );
        }
        $wssCpt    = get_inject_obj(WsServerComponent::class);
        $wstkCpt   = get_inject_obj(WsTokenComponent::class);
        $serverSet = $wssCpt->getServerSetCached();//在线服务器集合(进程级短缓存,O(1) isset 查找)
        //下限 clamp 1s,防 env 配 0(=BLPOP 永久阻塞)/负导致退化
        $timeout = max(1, (int) config('websocket.realtime_online.timeout', self::REALTIME_ONLINE_TIMEOUT));
        $redis   = $this->redis;

        //结果默认全 false;后续仅把"确有在线连接"的值翻 true
        $result = array_fill_keys($values, false);

        //① 并发取每个值的绑定反向索引【字段名】(HKEYS;field 即 sv:fd,无需读/decode 整份 JSON value)
        $parallel = new Parallel($concurrent);
        foreach ($values as $value) {
            $parallel->add(fn () => $wstkCpt->getDimBindFields($dim, $value), (string) $value);
        }
        $fieldsByValue = $parallel->wait(false);//(string)value => [field, ...];取失败的值缺席→保持 false

        //② 跨所有值按服务器汇总 fd(同服务器所有用户合一批);记每值占用 (sv,fd) 以回填;同 value 失效项收集后一次 HDEL
        $valueConns = [];//(string)value => [[sv, fd], ...]
        $svFds      = [];//sv => [fd => fd]  (去重)
        foreach ($values as $value) {
            $fields = $fieldsByValue[(string) $value] ?? [];
            if (empty($fields) || !is_array($fields)) {
                continue;
            }
            $stale = [];
            foreach ($fields as $field) {
                //field = sv:fd;serverKey 可能含冒号(IPv6/自定义),用【最后一个】冒号拆,fd 取末段
                $pos = strrpos((string) $field, ':');
                if ($pos === false) {
                    continue;
                }
                $sv = substr($field, 0, $pos);
                $fd = (int) substr($field, $pos + 1);
                if (!isset($serverSet[$sv])) {
                    $stale[] = $field;//对应服务器已无效,收集待批量删
                    continue;
                }
                $valueConns[(string) $value][] = [$sv, $fd];
                $svFds[$sv][$fd]               = $fd;
            }
            if (!empty($stale)) {
                $wstkCpt->delDimBindFields($dim, $value, $stale);//同 value 失效项一次性 HDEL
            }
        }
        if (empty($svFds)) {
            return $result;//无任何有效连接,全 false
        }

        //③ 每服务器只下一个批量 job(该服务器上所有待查用户的全部 fd 一次核验),单 rid(本次调用内共用,跨调用随机隔离 #7)
        $rid = bin2hex(random_bytes(8));
        foreach ($svFds as $sv => $fds) {
            AsyncQueue::push((new CheckOnlineJob(array_values($fds), $rid))->setQueue(CheckOnlineJob::resolveQueue($sv)));
        }

        //④ 等结果:消费方核验完某服务器后,把结果 {sv,pairs} 直接 rPush 到就绪信号;这里 BLPOP 即时唤醒并取用。
        //   BLPOP 用 1s 整秒分片 + 截止时间(不依赖 phpredis 浮点超时);消费方没响应才走超时兜底。
        $readyKey  = WsKeys::checkReadyKey($rid);
        $pendingSv = $svFds;        // 待报告服务器集合(sv => fds),收齐一台移除一台
        $fdOnline  = [];            // sv => (fd => '1'/'0')
        try {
            $deadline = microtime(true) + $timeout;
            while (!empty($pendingSv) && microtime(true) < $deadline) {
                $sig = $redis->blPop([$readyKey], 1);//协程下非阻塞;无信号则 1s 后返回空,复查截止
                if (empty($sig)) {
                    continue;
                }
                //就绪信号直接带结果:JSON {sv, pairs:{fd:'1'/'0'}}(CheckOnlineJob 一次性塞回,无 result hash 旁路)
                $decoded = json_to_array($sig[1]);
                $sv      = (string) ($decoded['sv'] ?? '');
                if ($sv === '' || !isset($pendingSv[$sv])) {
                    continue;//非本批/重复/坏信号
                }
                $fdOnline[$sv] = (isset($decoded['pairs']) && is_array($decoded['pairs'])) ? $decoded['pairs'] : [];
                unset($pendingSv[$sv]);//该服务器已收齐
            }
        } catch (\Throwable $e) {
            //出错则按已收集到的部分结果归集,未收齐的值保持 false(超时兜底语义一致)
        } finally {
            $redis->del($readyKey);//只清就绪信号(无 result hash 需清,ready key 另有 TTL 兜底)
        }

        //⑤ 按值归集:该值名下任意 (sv,fd) 实时在线即判在线
        foreach ($valueConns as $value => $conns) {
            foreach ($conns as [$sv, $fd]) {
                if (($fdOnline[$sv][(string) $fd] ?? null) === '1') {
                    $result[$value] = true;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * 【心跳级】在线判断:读 presence 索引(ws:online:<dim>:<bucket>,field=value→json([sv,...]))——
     * 某维度值名下存在「在线 server」即视为在线。presence 由绑定写路径(setBind/refreshBind)从反向索引派生维护、
     * 带 field 级 HEXPIRE(BIND_CACHE_TIME)自洁;此处只读、纯批量。
     * 精度为心跳/TTL 粒度:刚断的连接最多 ≤BIND_CACHE_TIME 秒内仍可能判在线(干净断连也走 TTL,见 WsTokenComponent presence 语义)。
     *
     * 性能:values 按 bucket 分组,每个 bucket 一次 HMGET(单 key,集群安全),N 个值 → 至多 min(bucket数, N) 次 HMGET,
     * 不再每值一次 HKEYS;HMGET 之间按 $concurrent 并发重叠 RTT。"server 下线即排除"靠读时与 getServerSetCached() 取交集保住。
     *
     * 【取值约定 / 如何"查全量"】$values 必须是**调用方显式列出的、要查询的维度值清单**。
     * 本方法没有"全量"哨兵,也不自动发现"该维度下所有已绑定的值"——把你关心的全部值一次传进来即可(完整清单也行)。
     * 真要枚举某维度当前所有在线值,需 SCAN `<prefix>online:<dim>:*` 键空间(集群跨节点、开销大),本方法不内置。
     * @param string $dim    维度名(须在 WsBindStrategy::onlineCheckDimensions() ∪ uniqueDimensions() 内,否则抛异常;presence 只为这些维度维护)
     * @param array  $values 要查询的维度值清单(显式列出,无"全量"哨兵;大批量/完整清单均可)
     * @return array value => bool
     */
    public function checkHeartbeatOnlineByDim(string $dim, array $values, int $concurrent = 100)
    {
        $this->assertOnlineCheckDim($dim);
        $values = array_values(array_unique($values));
        if ($values === []) {
            return [];
        }
        $wssCpt    = get_inject_obj(WsServerComponent::class);
        $serverSet = $wssCpt->getServerSetCached();//在线服务器集合(进程级短缓存,O(1) isset 查找)
        $redis     = $this->redis;

        $result = array_fill_keys($values, false);

        //按 bucket 分组:同 bucket 的多个 value 一次 HMGET 取回(field=value)
        $byBucket = [];//bucketKey => [ (string)value => value ]
        foreach ($values as $value) {
            $byBucket[WsKeys::presenceKey($dim, $value)][(string) $value] = $value;
        }

        //每 bucket 一次 HMGET(并发重叠);解析 server 集合,任一 sv 在线即在线
        $parallel = new Parallel($concurrent);
        foreach ($byBucket as $bucketKey => $vals) {
            $parallel->add(
                function () use ($redis, $bucketKey, $vals, $serverSet) {
                    $raw = $redis->hMGet($bucketKey, array_keys($vals));//[ (string)value => json|false ]
                    $out = [];//value => bool
                    foreach ($vals as $sval => $value) {
                        $json   = $raw[$sval] ?? false;
                        $online = false;
                        if (is_string($json) && $json !== '') {
                            $map = json_to_array($json);//{sv:{fd:1}} → 取 server keys
                            if (is_array($map)) {
                                foreach ($map as $sv => $fds) {
                                    if (isset($serverSet[$sv])) {
                                        $online = true;//任一所在 server 仍在线 → 在线
                                        break;
                                    }
                                }
                            }
                        }
                        $out[$value] = $online;
                    }
                    return $out;
                },
                $bucketKey
            );
        }
        foreach ($parallel->wait(false) as $out) {
            if (is_array($out)) {
                foreach ($out as $v => $b) {
                    $result[$v] = $b;
                }
            }
        }
        return $result;
    }

    /**
     * 【心跳级·全量】返回该维度当前**在线的全部值**——无需调用方传 values。
     * 仅 presence 索引(bucket 化)使这成为可能:遍历 N=presence_bucket_num 个【已知 bucket key】(非 keyspace SCAN、集群安全),
     * 各 bucket HGETALL → 汇总 value→server 集合,任一 server 在线即在线。只返回在线值(value=>true)。
     *
     * 成本:N 次 HGETALL(默认 4,config 可调;并发重叠)——无论在线值多少都遍历全部 bucket,故适合"偶尔取全量在线快照"
     * (如后台/统计),不适合高频热路径。返回体 O(M)(M=该维度在线值数)。
     * 精度同心跳级:刚断连的值最多 ≤BIND_CACHE_TIME 内仍可能在列(presence TTL 滞留)。
     * 限制:仅对 addressableDimensions() 维度有 presence;presence_bucket_num 中途改值会漏读旧桶,改值需在无流量窗口。
     * @return array value => true(仅在线值;不在线/无 presence 的不返回)
     */
    public function checkHeartbeatOnlineAllByDim(string $dim, int $concurrent = 100): array
    {
        $this->assertOnlineCheckDim($dim);
        $serverSet = get_inject_obj(WsServerComponent::class)->getServerSetCached();
        $redis     = $this->redis;
        $count     = WsKeys::presenceBucketCount();

        //遍历全部 bucket,每个 HGETALL → 该桶内 value=>serverList;按在线 server 过滤(并发重叠)
        $parallel = new Parallel($concurrent);
        for ($i = 0; $i < $count; $i++) {
            $bucketKey = WsKeys::presenceBucketKey($dim, $i);
            $parallel->add(
                function () use ($redis, $bucketKey, $serverSet) {
                    $all = $redis->hGetAll($bucketKey);//[ value => json([sv,...]) ]
                    $out = [];//value => true(仅在线)
                    if (is_array($all)) {
                        foreach ($all as $value => $json) {
                            $map = json_to_array($json);//{sv:{fd:1}} → 取 server keys
                            if (!is_array($map)) {
                                continue;
                            }
                            foreach ($map as $sv => $fds) {
                                if (isset($serverSet[$sv])) {
                                    $out[$value] = true;
                                    break;
                                }
                            }
                        }
                    }
                    return $out;
                },
                $i
            );
        }

        $result = [];
        foreach ($parallel->wait(false) as $out) {
            if (is_array($out)) {
                $result += $out;//各 value 落唯一 bucket,无键冲突
            }
        }
        return $result;
    }

    /**
     * 校验 $dim 可被心跳在线检查:必须 ∈ onlineCheckDimensions() ∪ uniqueDimensions()(框架自动并入 unique 防漏设)。
     * 否则抛异常——presence 只为这些维度维护;低基数分组维度(device_type/channel 等)仅供 pushToDimMessage 寻址推送,不支持在线检查。
     */
    private function assertOnlineCheckDim(string $dim): void
    {
        $s   = get_inject_obj(WsBindStrategyInterface::class);
        $set = array_flip(array_merge($s->onlineCheckDimensions(), $s->uniqueDimensions()));
        if (!isset($set[$dim])) {
            throw new \InvalidArgumentException(
                "checkHeartbeatOnlineByDim 仅支持 onlineCheckDimensions(∪uniqueDimensions) 维度,[{$dim}] 不在其中;"
                . "低基数分组维度仅用于 pushToDimMessage,不支持在线检查"
            );
        }
    }

    /**
     * 关闭客户端
     * @param $clients array 服务器标识{"192-168-6-9":[1,4,6]}（sv=>fds:-1表示所有）
     * @return bool
     */
    public function closeClient($clients = [])
    {
        $ret = [];
        if (!empty($clients)) {//指定
            foreach ($clients as $sv => $fds) {
                //空 fd 数组(误传)→ 跳过,避免 [] ?: '-1' 退化成"关该 server 全体";"关全体"只由显式 '-1' 表达
                if (is_array($fds) && count($fds) === 0) {
                    continue;
                }
                $job = new CloseMessageJob($fds ?: '-1');
                $job->setQueue(CloseMessageJob::resolveQueue($sv));
                $ret[] = AsyncQueue::push($job);
            }
        } else {//所有
            $servers = get_inject_obj(WsServerComponent::class)->getServerList();
            foreach ($servers as $server) {
                //分发到对应服务器的控制队列(由 CloseMessageJob 决定:开关开→独立控制队列,关→回落消息队列)
                $job = new CloseMessageJob('-1');
                $job->setQueue(CloseMessageJob::resolveQueue($server));
                $ret[] = AsyncQueue::push($job);
            }
        }

        if (!in_array(true, $ret)) {
            //一个都没有成功，返回失败
            return false;
        }
        return true;
    }

    /**
     * 将消息推送到所有人
     * @param $cmd
     * @param $message
     * @param int $delay
     * @param array $nsfd
     * @return bool
     */
    public function pushPubMessage($cmd, $message, $delay = 0, array $nsfd = [])
    {
        $message = $this->formatMessage($cmd, $message);

        $wssCpt  = get_inject_obj(WsServerComponent::class);
        $servers = $wssCpt->getServerList();
        //排除FD 的 sv 主动归一化(getServerKey 幂等),避免调用方传原始点分 IP 导致排除静默失效
        $exclSv  = isset($nsfd['sv']) ? $wssCpt->getServerKey($nsfd['sv']) : null;
        $exclFd  = (int) ($nsfd['fd'] ?? 0);
        $ret     = [];
        foreach ($servers as $server) {
            //每台一份独立副本:nfd(不推送的FD)只对其所在服务器有效,不能串到其它服务器的 Job(否则误排除无关连接)
            $msg = $message;
            if ($exclSv !== null && $exclSv === $server) {//不推送的FD,必须与所在服务器对应
                $msg['nfd'] = $exclFd;
            }
            //分发到对应服务器的消息队列
            $job = new PushMessageJob($cmd, $msg);
            $job->setQueue(PushMessageJob::resolveQueue($server));
            $ret[] = AsyncQueue::push($job, intval($delay));
        }
        if (!in_array(true, $ret)) {
            //一个都没有成功，返回失败
            return false;
        }
        return true;
    }

    /**
     * 按绑定维度寻址下发（维度由 WsBindStrategy 定义，如 account_id / device 等）。
     * @param string $dim   维度名（须在 strategy->addressableDimensions() 内、setBind 建过反向索引）
     * @param mixed $value  维度值
     * @param $cmd
     * @param $message
     * @param int $delay
     * @param array|null $binds 反向索引(可外部预取传入); 不传则按 (dim,value) 取
     * @return bool
     */
    public function pushToDimMessage($dim, $value, $cmd, $message, $delay = 0, $binds = null)
    {
        if (empty($binds)) {
            $binds = get_inject_obj(WsTokenComponent::class)->getDimBind($dim, $value);
            if (empty($binds)) {
                return false;
            }
        }

        $servers = get_inject_obj(WsServerComponent::class)->getServerList();

        $ret = [];
        foreach ($binds as $field => $serverFdJson) {
            $serverFd = json_to_array($serverFdJson);
            if (!in_array($serverFd['sv'], $servers)) {
                //记录关系已过期无效,删除该连接项(field=sv:fd)
                get_inject_obj(WsTokenComponent::class)->delDimBind($dim, $value, $field);
                continue;
            }
            $msg = $this->formatMessage($cmd, $message, $serverFd['fd']);
            //分发到对应服务器的消息队列
            $job = new PushMessageJob($cmd, $msg);
            $job->setQueue(PushMessageJob::resolveQueue($serverFd['sv']));
            $ret[] = AsyncQueue::push($job, intval($delay));
        }

        if (in_array(true, $ret)) {
            return true;
        }
        return false;
    }

    private function formatMessage($cmd, $message, $fd = 0)
    {
        //指定目标连接:>0 由 PushMessageJob 走本机定向 Sender;0(广播)走 WsBroadcast::toAll。
        //必须写入:Job 据 message['fd'] 区分定向/广播,缺省即按广播处理。
        $message['fd'] = (int) $fd;
        return $message;
    }

}
