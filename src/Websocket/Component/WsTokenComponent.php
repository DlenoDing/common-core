<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;
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
 * 绑定策略无包内默认：业务必须在 dependencies.php 绑定 WsBindStrategyInterface（脚手架自带默认实现
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
        //标准身份(握手写入的头);自定义维度由业务 strategy 在 bindDimensions 内自行补充(可读各自的头)
        $identity = [
            'account_id' => get_header_val(WsKeys::HEADER_ACCOUNT_ID, 0),
            'token'      => get_header_val(WsKeys::HEADER_TOKEN, ''),
        ];
        $dims        = $this->bindStrategy->bindDimensions((int) $fd, $identity); // dim => value
        $addressable = $this->bindStrategy->addressableDimensions();              // [dim, ...]

        $serverFd    = $this->getServerFd($fd);
        $serverFdStr = $this->serverFdField($serverFd);

        //正向:serverFd 主绑定 => 完整维度(Close 时据此反删各反向索引)
        $this->redis->set($this->getSfdBindKey($serverFd), array_to_json($dims), WsKeys::BIND_CACHE_TIME);

        //反向:每个可寻址维度建一份索引, field=sv:fd(每连接唯一,杜绝同 token 覆盖)
        foreach ($addressable as $dim) {
            if (!array_key_exists($dim, $dims) || $dims[$dim] === null) {
                continue;
            }
            $dimKey = WsKeys::bindDimKey($dim, $dims[$dim]);
            $this->redis->hSet($dimKey, $serverFdStr, array_to_json($serverFd));
            //过期时间与用户数据缓存一致
            $this->redis->expire($dimKey, WsKeys::BIND_CACHE_TIME);
        }
    }

    /**
     * 刷新绑定数据过期时间
     * @param $fd
     */
    public function refreshBind($fd)
    {
        $serverFd   = $this->getServerFd($fd);
        $sfdBindKey = $this->getSfdBindKey($serverFd);
        //据主绑定里的维度,刷新主绑定 + 各可寻址反向索引
        $dims = json_to_array($this->redis->get($sfdBindKey));
        $this->redis->expire($sfdBindKey, WsKeys::BIND_CACHE_TIME);
        if (!empty($dims)) {
            foreach ($this->bindStrategy->addressableDimensions() as $dim) {
                if (!array_key_exists($dim, $dims) || $dims[$dim] === null) {
                    continue;
                }
                $this->redis->expire(WsKeys::bindDimKey($dim, $dims[$dim]), WsKeys::BIND_CACHE_TIME);
            }
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
        //取主绑定的维度,反删每个可寻址反向索引(field=sv:fd)
        $dims = json_to_array($this->redis->get($sfdBindKey));
        if (!empty($dims)) {
            foreach ($this->bindStrategy->addressableDimensions() as $dim) {
                if (!array_key_exists($dim, $dims) || $dims[$dim] === null) {
                    continue;
                }
                $this->redis->hDel(WsKeys::bindDimKey($dim, $dims[$dim]), $serverFdStr);
            }
        }
        //删除serverFd主绑定数据
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

    /**
     * account_id 维度反向索引（BC 包装）
     * @param $accountId
     * @return array
     */
    public function getAccountIdBind($accountId)
    {
        return $this->getDimBind('account_id', $accountId);
    }

    /**
     * 删除 account_id 维度反向索引项（BC 包装；$field 为 sv:fd）
     * @return int
     */
    public function delAccountIdBind($accountId, $field)
    {
        return $this->delDimBind('account_id', $accountId, $field);
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
