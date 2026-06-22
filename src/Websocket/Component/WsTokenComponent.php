<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;
use Dleno\CommonCore\Websocket\Support\WsIdentity;
use Dleno\CommonCore\Websocket\Support\WsKeys;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * WS 连接身份绑定表（纯基建）。
 *
 * 绑定维度由 WsBindStrategyInterface 决定（业务可注入自定义实现：token / device / 多端…）。
 *   - 正向 主绑定 <prefix>bind:sfd:<sv>:<fd> => json(全部维度)        （Close 时据此反删各反向索引）
 *   - 反向 索引   <prefix>bind:<dim>:<value> (hash) field=<sv:fd> => json(serverFd)
 *                 对 strategy->addressableDimensions() 里每个维度各建一份，可按维度寻址下发。
 *
 * 反向索引 field 用 "sv:fd"（每连接唯一），同账号多连接互不覆盖。
 * 绑定策略无包内默认：业务必须在 dependencies.php 绑定 WsBindStrategyInterface（业务侧默认实现
 * App\WebSocket\Bind\DefaultWsBindStrategy = 只绑 account_id；需要多端/设备维度时改成自己的实现）。
 */
class WsTokenComponent extends BaseCoreComponent
{
    #[Inject]
    protected Redis $redis;

    #[Inject]
    protected WsBindStrategyInterface $bindStrategy;

    /**
     * 设置连接绑定
     * @param $fd
     */
    public function setBind($fd)
    {
        //完整身份(握手中间件经 WsIdentity::set 存入 = 钩子返回的账户字段 + token)→ strategy 可据此定义任意维度。
        //无身份(中间件未 set,如握手未通过/未接入)→ 不绑,避免写出无意义/残缺的绑定。
        $identity = WsIdentity::get();
        if (empty($identity)) {
            return;
        }
        $dims = $this->bindStrategy->bindDimensions((int) $fd, $identity); // dim => value
        //无维度 → 不绑
        if (empty($dims)) {
            return;
        }
        $addressable = $this->bindStrategy->addressableDimensions();      // [dim, ...]

        $serverFd    = $this->getServerFd($fd);
        $serverFdStr = $this->serverFdField($serverFd);

        //正向:serverFd 主绑定 => 完整维度(Close 时据此反删各反向索引)
        $this->redis->set($this->getSfdBindKey($serverFd), array_to_json($dims), WsKeys::BIND_CACHE_TIME);

        //反向:无可寻址维度则不做反向存储;否则每个可寻址维度建一份索引, field=sv:fd(每连接唯一,杜绝同 token 覆盖)
        if (!empty($addressable)) {
            foreach ($addressable as $dim) {
                if (!array_key_exists($dim, $dims) || $dims[$dim] === null) {
                    continue;
                }
                $dimKey = WsKeys::bindDimKey($dim, $dims[$dim]);
                $this->redis->hSet($dimKey, $serverFdStr, array_to_json($serverFd));
                //过期时间与用户数据缓存一致：HEXPIRE 每 field 独立 TTL(死连接 field 到期自洁)
                $this->expireDimTtl($dimKey, $serverFdStr);
                //同步心跳 presence 索引(从反向索引重算该值的在线 server 集合)
                $this->setPresence($dim, $dims[$dim]);
            }

            //单连接维度(uniqueDimensions):同维度值已有别的连接 → 踢旧(后登录踢前登录)。
            //空(默认) → 直接跳过,零额外开销,保持"同维度值多连接"的默认行为。
            $unique = $this->bindStrategy->uniqueDimensions();
            if (!empty($unique)) {
                $this->enforceUnique($unique, $dims, $addressable, $serverFdStr);
            }
        }
    }

    /**
     * 对"单连接维度"强制唯一:本连接刚写入反向索引后,踢掉同维度值下的其它连接(后登录踢前登录)。
     * 性能:仅在 uniqueDimensions 非空时进入;唯一维度的反向 hash 恒只含本连接(±个别旧连接),hGetAll 极小;
     * 仅当真有旧连接才下发 closeClient。不碰发消息/心跳路径。
     */
    private function enforceUnique(array $unique, array $dims, array $addressable, string $serverFdStr): void
    {
        $servers = null; //懒取在线服务器列表:仅当真有旧连接要处理时才读一次
        foreach ($unique as $dim) {
            //仅对"本连接已绑定 + 建了反向索引(addressable)"的维度生效;否则无从反查旧连接
            if (!array_key_exists($dim, $dims) || $dims[$dim] === null || !in_array($dim, $addressable, true)) {
                continue;
            }
            $dimKey = WsKeys::bindDimKey($dim, $dims[$dim]);
            $all    = $this->redis->hGetAll($dimKey);
            if (!is_array($all) || count($all) <= 1) {
                continue; //只有本连接 → 无需踢
            }
            if ($servers === null) {
                $servers = get_inject_obj(WsServerComponent::class)->getServerList();
            }
            $stale = []; //sv => [fd, ...]
            foreach ($all as $field => $sfdJson) {
                if ($field === $serverFdStr) {
                    continue; //不踢自己
                }
                $sf = json_to_array($sfdJson);
                if (!isset($sf['sv'], $sf['fd'])) {
                    continue;
                }
                if (!in_array($sf['sv'], $servers, true)) {
                    //该 field 所属 server 已下线 → 是 stale,直接 hDel 自愈,不给死队列派无用 close Job
                    $this->redis->hDel($dimKey, $field);
                    continue;
                }
                $stale[$sf['sv']][] = $sf['fd'];
            }
            if (!empty($stale)) {
                //踢旧:本机/跨机统一经 closeClient(到各 server 队列);旧连接 onClose→unBind 自清其全部绑定。
                get_inject_obj(WsPushMsgComponent::class)->closeClient($stale);
            }
        }
    }

    /**
     * 刷新绑定数据过期时间
     * @param $fd
     */
    public function refreshBind($fd)
    {
        $serverFd    = $this->getServerFd($fd);
        $serverFdStr = $this->serverFdField($serverFd);
        $sfdBindKey  = $this->getSfdBindKey($serverFd);
        //据主绑定里的维度,刷新主绑定 + 各可寻址反向索引
        $dims = json_to_array($this->redis->get($sfdBindKey));
        //无主绑定(已过期/未绑) → 无可刷新
        if (empty($dims)) {
            return;
        }
        $this->redis->expire($sfdBindKey, WsKeys::BIND_CACHE_TIME);
        //无可寻址维度则不刷新反向索引
        $addressable = $this->bindStrategy->addressableDimensions();
        if (!empty($addressable)) {
            foreach ($addressable as $dim) {
                if (!array_key_exists($dim, $dims) || $dims[$dim] === null) {
                    continue;
                }
                $this->expireDimTtl(WsKeys::bindDimKey($dim, $dims[$dim]), $serverFdStr);
                //续期心跳 presence(只 HEXPIRE;field 缺失则懒重建)——server 集合未变,无需重写 value
                $this->refreshPresence($dim, $dims[$dim]);
            }
        }
    }

    /**
     * 给反向索引续期(HEXPIRE 每-field 独立 TTL):死连接(无 onClose)的 field 到期后独立过期,
     * 不受同维度其他活跃连接续命影响,hash 全空后 Redis 自动删。
     * 注意:用 field 级 HEXPIRE 而非 key 级 expire——否则 key 级 TTL 一触发会把刚续过的活 field 一起删。
     */
    private function expireDimTtl(string $dimKey, string $field): void
    {
        $this->hExpireField($dimKey, $field);
    }

    /**
     * 对 hash 的单个 field 设 BIND_CACHE_TIME 的 HEXPIRE(7.4+)。
     * rawCommand 不走 OPT_PREFIX,手动补全前缀(phpredis 部分版本无 hExpire() 方法,统一 rawCommand)。
     * @return mixed rawCommand 结果(数组,如 [1] 已设/[-2] 无此 field)
     */
    private function hExpireField(string $key, string $field)
    {
        $full = (string) $this->redis->getOption(\Redis::OPT_PREFIX) . $key;
        return $this->redis->rawCommand('HEXPIRE', $full, WsKeys::BIND_CACHE_TIME, 'FIELDS', 1, $field);
    }

    //——— 心跳 presence 索引(ws:online:<dim>:<bucket>,field=value→json([sv,...]))———
    //写时从反向索引派生该值当前所在的在线 server 集合;读(checkHeartbeatOnlineByDim)按 bucket 批量 HMGET。
    //语义(codex 校验):unBind 不删 presence(交 field TTL 过期,避免跨 key 竞态误删活跃连接);
    //refreshBind 只 HEXPIRE 续命,field 不存在(被竞态删/过期但连接仍活)则懒重建一次。

    /**
     * 从反向索引 HKEYS 派生该维度值当前所在的【在线 server 集合】(field=sv:fd → 取 sv,去重)。
     * @return string[] sv 列表(可能为空=该值当前无任何绑定)
     */
    private function deriveValueServers(string $dim, $value): array
    {
        $fields = $this->redis->hKeys(WsKeys::bindDimKey($dim, $value));
        if (!is_array($fields)) {
            return [];
        }
        $svs = [];
        foreach ($fields as $field) {
            //field = sv:fd;serverKey 可能含冒号(IPv6/自定义),用最后一个冒号拆,sv 取前段
            $pos = strrpos((string) $field, ':');
            if ($pos === false) {
                continue;
            }
            $sv = substr((string) $field, 0, $pos);
            $svs[$sv] = $sv;//去重
        }
        return array_values($svs);
    }

    /**
     * 重算并写 presence:从反向索引派生 server 集合 → HSET 该 value 的 field + HEXPIRE。
     * 无任何绑定(派生为空)则不写(让旧 field 随 TTL 过期),不主动 HDEL。
     */
    private function setPresence(string $dim, $value): void
    {
        $svs = $this->deriveValueServers($dim, $value);
        if (empty($svs)) {
            return;
        }
        $pKey = WsKeys::presenceKey($dim, $value);
        $this->redis->hSet($pKey, (string) $value, array_to_json($svs));
        $this->hExpireField($pKey, (string) $value);
    }

    /**
     * 续期 presence field;若 field 不存在(HEXPIRE 返回 -2:被跨 key 竞态误删/过期但连接仍活)→ 懒重建一次。
     */
    private function refreshPresence(string $dim, $value): void
    {
        $ret = $this->hExpireField(WsKeys::presenceKey($dim, $value), (string) $value);
        if (!is_array($ret) || ((int) ($ret[0] ?? -2)) === -2) {
            $this->setPresence($dim, $value);
        }
    }

    /**
     * ws连接解除绑定数据
     * @param $fd
     */
    public function unBind($fd)
    {
        $serverFd    = $this->getServerFd($fd);
        $serverFdStr = $this->serverFdField($serverFd);
        $sfdBindKey  = $this->getSfdBindKey($serverFd);
        //取主绑定的维度,反删每个可寻址反向索引(field=sv:fd);无维度或无可寻址维度则跳过反删
        $dims = json_to_array($this->redis->get($sfdBindKey));
        if (!empty($dims)) {
            $addressable = $this->bindStrategy->addressableDimensions();
            if (!empty($addressable)) {
                foreach ($addressable as $dim) {
                    if (!array_key_exists($dim, $dims) || $dims[$dim] === null) {
                        continue;
                    }
                    $this->redis->hDel(WsKeys::bindDimKey($dim, $dims[$dim]), $serverFdStr);
                }
            }
        }
        //始终删除 serverFd 主绑定数据(清理,无论有无维度)
        $this->redis->del($sfdBindKey);
    }

    /**
     * 按维度取反向索引：field(sv:fd) => json(serverFd)
     * @param string $dim
     * @param mixed $value
     * @return array
     */
    public function getDimBind($dim, $value)
    {
        $data = $this->redis->hGetAll(WsKeys::bindDimKey($dim, $value));
        return is_array($data) ? $data : [];
    }

    /**
     * 按维度只取反向索引的 field 列表(HKEYS):field 本身即 "sv:fd",在线判断只需 sv/fd,
     * 用此可省去 HGETALL 读取 + JSON decode 整份 value(多连接维度收益明显)。
     * @return string[] [ "sv:fd", ... ]
     */
    public function getDimBindFields($dim, $value)
    {
        $data = $this->redis->hKeys(WsKeys::bindDimKey($dim, $value));
        return is_array($data) ? $data : [];
    }

    /**
     * 删除某维度反向索引里的一个连接项（field=sv:fd）
     * @return int
     */
    public function delDimBind($dim, $value, $field)
    {
        return $this->redis->hDel(WsKeys::bindDimKey($dim, $value), $field);
    }

    /**
     * 批量删除某维度反向索引里的多个连接项(一次 HDEL key field1 field2 …),
     * 供在线判断收集同一 value 下的失效项后一次性清理,避免逐 field 同步打 Redis。
     * @param string[] $fields
     * @return int 删除的 field 数
     */
    public function delDimBindFields($dim, $value, array $fields)
    {
        if (empty($fields)) {
            return 0;
        }
        return (int) $this->redis->hDel(WsKeys::bindDimKey($dim, $value), ...$fields);
    }

    public function getServerFd($fd)
    {
        $serverFd       = [];
        $serverFd['sv'] = get_inject_obj(WsServerComponent::class)->getServerKey();
        $serverFd['fd'] = $fd;
        return $serverFd;
    }

    /**
     * 反向索引里标识一个连接的唯一 field：sv:fd
     */
    private function serverFdField(array $serverFd): string
    {
        return $serverFd['sv'] . ':' . $serverFd['fd'];
    }

    private function getSfdBindKey(array $serverFd)
    {
        return WsKeys::bindSfdKey($serverFd['sv'], $serverFd['fd']);
    }
}
