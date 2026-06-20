<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;
use Dleno\CommonCore\Websocket\Support\WsIdentity;
use Dleno\CommonCore\Websocket\Support\WsKeys;
use Dleno\CommonCore\Websocket\Support\WsRedisCap;
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
                //过期时间与用户数据缓存一致：7.4+ 下每 field 独立 TTL(死连接 field 自洁)，否则整 hash key TTL
                $this->expireDimTtl($dimKey, $serverFdStr);
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
            }
        }
    }

    /**
     * 给反向索引续期。
     * - Redis 7.4+：HEXPIRE 给"每 field 独立 TTL"——死连接(无 onClose)的 field 60s 后独立过期,
     *   不受同维度其他活跃连接续命影响,hash 全空后 Redis 自动删。
     * - 7.4 以下：整 hash key 级 EXPIRE + 注册表登记,残留 field 由 WsBindSweeper 低频清扫兜底。
     * 注意：HEXPIRE 分支【不】下 key 级 expire——否则 key 级 TTL 一触发会把刚续过的活 field 一起删。
     */
    private function expireDimTtl(string $dimKey, string $field): void
    {
        if (WsRedisCap::supportsHExpire($this->redis)) {
            //rawCommand 不走 OPT_PREFIX,手动补全前缀(phpredis 6.2 也无 hExpire() 方法,统一 rawCommand)
            $full = (string) $this->redis->getOption(\Redis::OPT_PREFIX) . $dimKey;
            $this->redis->rawCommand('HEXPIRE', $full, WsKeys::BIND_CACHE_TIME, 'FIELDS', 1, $field);
        } else {
            $this->redis->expire($dimKey, WsKeys::BIND_CACHE_TIME);
            //<7.4：把本反向索引 key 登记进注册表,供 WsBindSweeper 只遍历真实索引(不全库 SCAN)。
            //SADD 幂等;7.4+ 走上面分支不登记,注册表保持空、清扫也不跑。
            $this->redis->sAdd(WsKeys::bindIndexKey(), $dimKey);
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
     * 删除某维度反向索引里的一个连接项（field=sv:fd）
     * @return int
     */
    public function delDimBind($dim, $value, $field)
    {
        return $this->redis->hDel(WsKeys::bindDimKey($dim, $value), $field);
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
