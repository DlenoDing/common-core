<?php

declare (strict_types=1);

namespace Dleno\CommonCore\Websocket\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Websocket\Support\WsKeys;
use Dleno\CommonCore\Websocket\Job\CheckOnlineJob;
use Dleno\CommonCore\Websocket\Job\CloseMessageJob;
use Dleno\CommonCore\Websocket\Job\PushMessageJob;
use Dleno\CommonCore\Tools\AsyncQueue\AsyncQueue;
use Hyperf\Coroutine\Parallel;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Hyperf\WebSocketServer\Sender;

use function Hyperf\Coroutine\wait;
use function Hyperf\Support\env;

/**
 * WS 消息推送/在线检查/关闭 编排器（纯基建）。
 *
 * - send/close：本机 Sender 直推/断连（压缩开关读 env，无法走配置中心）
 * - pushPubMessage：广播；pushToDimMessage：按业务维度(WsBindStrategy)反向索引定向，分发 PushMessageJob 到各 server 的 per-IP 队列
 * - checkOnlineByDim：派 CheckOnlineJob 到目标 server 队列 + 轮询 check key 聚合在线判定
 * - closeClient：派 CloseMessageJob
 *
 * 维度名(account_id / device …)一律由调用方(业务)传入，本组件不绑定任何具体维度。
 *
 * 队列名/在线检查 key 全部走 WsKeys（ws:queue:message:/ws:check:online:）。
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

    /**
     * 按绑定维度批量在线检查（维度由业务 WsBindStrategy 定义，须在 addressableDimensions() 内）。
     * @param string $dim    维度名（如 account_id / device）
     * @param array  $values 维度值列表
     * @return array value => bool（任一连接在线即 true）
     */
    public function checkOnlineByDim(string $dim, array $values, int $concurrent = 100)
    {
        $wssCpt   = get_inject_obj(WsServerComponent::class);
        $wstkCpt  = get_inject_obj(WsTokenComponent::class);
        $servers  = $wssCpt->getServerList();
        $parallel = new Parallel($concurrent);
        foreach ($values as $value) {
            $parallel->add(
                function () use ($wstkCpt, $servers, $dim, $value) {
                    $valueBinds = $wstkCpt->getDimBind($dim, $value);
                    if (empty($valueBinds)) {
                        return false;
                    }
                    //按服务器聚合该维度值的全部 fd(同一服务器的多连接合并为一个批量任务)
                    $svFds = [];//sv => [fd, ...]
                    $polls = [];//[ ['sv'=>, 'fd'=>], ... ]
                    foreach ($valueBinds as $field => $serverFdJson) {
                        $serverFd = json_to_array($serverFdJson);
                        $sv = $serverFd['sv'];
                        $fd = $serverFd['fd'];
                        if (!in_array($sv, $servers)) {
                            $wstkCpt->delDimBind($dim, $value, $field);//对应服务器已无效,删除该连接项(field=sv:fd)
                            continue;
                        }
                        $svFds[$sv][] = $fd;
                        $polls[]      = ['sv' => $sv, 'fd' => $fd];
                    }
                    if (empty($polls)) {
                        return false;
                    }
                    //先清除历史结果 key,避免上一次"提前命中即返回"残留的 stale 结果干扰本次判定
                    foreach ($polls as $p) {
                        $this->redis->del(self::getCheckKey($p['sv'], $p['fd']));
                    }
                    //每个服务器一个批量任务(该用户在该服务器上的全部 fd)
                    foreach ($svFds as $sv => $fds) {
                        $job = (new CheckOnlineJob($fds))->setQueue(self::getQueue($sv));
                        AsyncQueue::push($job);
                    }
                    //轮询结果:任一 fd 在线即判该用户在线
                    try {
                        return (bool)wait(function () use ($polls) {
                            $pending = [];
                            foreach ($polls as $p) {
                                $pending[$p['sv'] . ':' . $p['fd']] = self::getCheckKey($p['sv'], $p['fd']);
                            }
                            $i = 0;
                            while (!empty($pending) && ($i++) < 500) {
                                foreach ($pending as $id => $key) {
                                    if ($this->redis->exists($key)) {
                                        $val = $this->redis->get($key);
                                        $this->redis->del($key);
                                        unset($pending[$id]);
                                        if ($val) {
                                            return true;//命中在线('1' 真值,'0' 假值)
                                        }
                                    }
                                }
                                if (empty($pending)) {
                                    break;//全部已读且无在线
                                }
                                \Swoole\Coroutine::sleep(0.01);//协程让出,不阻塞 Worker
                            }
                            return false;
                        }, 2.0);
                    } catch (\Throwable $e) {
                        return false;
                    }
                },
                $value
            );
        }

        $onlines = $parallel->wait(false);
        return $onlines;
    }

    public static function getCheckKey($serverKey, $fd)
    {
        return WsKeys::checkKey($serverKey, $fd);
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
                $job = new CloseMessageJob($fds ?: '-1');
                $job->setQueue(self::getQueue($sv));
                $ret[] = AsyncQueue::push($job);
            }
        } else {//所有
            $servers = get_inject_obj(WsServerComponent::class)->getServerList();
            foreach ($servers as $server) {
                //分发到对应服务器的消息队列
                $job = new CloseMessageJob('-1');
                $job->setQueue(self::getQueue($server));
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

        $servers = get_inject_obj(WsServerComponent::class)->getServerList();
        $ret     = [];
        foreach ($servers as $server) {
            //每台一份独立副本:nfd(不推送的FD)只对其所在服务器有效,不能串到其它服务器的 Job(否则误排除无关连接)
            $msg = $message;
            if (($nsfd['sv'] ?? '') == $server) {//不推送的FD,必须与所在服务器对应(sv 须为 getServerKey 归一化后的值)
                $msg['nfd'] = (int) ($nsfd['fd'] ?? 0);
            }
            //分发到对应服务器的消息队列
            $job = new PushMessageJob($cmd, $msg);
            $job->setQueue(self::getQueue($server));
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
            $job->setQueue(self::getQueue($serverFd['sv']));
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

    /**
     * 获取实时消息队列名称
     * @param string|null $server
     * @return string
     */
    public static function getQueue($server = null)
    {
        $server = get_inject_obj(WsServerComponent::class)->getServerKey($server);
        return WsKeys::queueName($server);
    }
}
