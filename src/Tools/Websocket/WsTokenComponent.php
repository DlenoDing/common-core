<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Websocket;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * WS 连接 token / 身份绑定表（纯基建，下沉自脚手架）。
 *
 * 两条索引：
 *   - serverFd 主绑定 ws:bind:sfd:<sv>:<fd> => json{accountId, token}（Close 时反查身份）
 *   - 身份反向 ws:bind:account_id:<accountId> (hash) field=token => json(serverFd)（按身份寻址下发）
 *
 * 全部 key 走 WsKeys（字节级兼容脚手架 WsServerConf）。绑定数据字段保持 camelCase `accountId`
 * 与 token-as-hash-field 结构不变 —— 这是与在线连接/在途 job 的 BC 约束（见方案 §11.D），
 * 维度抽象（WsBindStrategy）留待后续版本接入，本类暂不接策略。
 * 业务侧用空子类 extends 之即可。
 */
class WsTokenComponent extends BaseCoreComponent
{
    #[Inject]
    protected Redis $redis;

    /**
     * 设置连接绑定
     * @param $fd
     */
    public function setBind($fd)
    {
        //Client数据
        $token     = get_header_val(WsKeys::HEADER_TOKEN, '');
        $accountId = get_header_val(WsKeys::HEADER_ACCOUNT_ID, 0);

        //serverFd
        $serverFd = $this->getServerFd($fd);

        //serverFd主绑定数据(Close时需要使用)
        $sfdBindKey  = $this->getSfdBindKey($serverFd);
        $sfdBindData = [
            'accountId' => $accountId,
            'token'     => $token,
        ];
        $this->redis->set($sfdBindKey, array_to_json($sfdBindData), WsKeys::BIND_CACHE_TIME);

        //accountId主绑定token=>serverFd列表
        $accountIdBindKey = $this->getAccountIdBindKey($accountId);
        $this->redis->hSet($accountIdBindKey, $token, array_to_json($serverFd));
        //过期时间与用户数据缓存一致
        $this->redis->expire($accountIdBindKey, WsKeys::BIND_CACHE_TIME);

        //var_dump('setBind', $sfdBindKey, $sfdBindData, $accountIdBindKey, $serverFd);
    }

    /**
     * 刷新绑定数据过期时间
     * @param $fd
     */
    public function refreshBind($fd)
    {
        //serverFd主绑定数据
        $serverFd   = $this->getServerFd($fd);
        $sfdBindKey = $this->getSfdBindKey($serverFd);
        //刷新过期时间
        $this->redis->expire($sfdBindKey, WsKeys::BIND_CACHE_TIME);

        //accountId主绑定token=>serverFd列表
        $accountId        = get_header_val(WsKeys::HEADER_ACCOUNT_ID, 0);
        $accountIdBindKey = $this->getAccountIdBindKey($accountId);
        //刷新过期时间
        $this->redis->expire($accountIdBindKey, WsKeys::BIND_CACHE_TIME);

        //var_dump('refreshBind', $sfdBindKey, $accountIdBindKey);
    }

    /**
     * ws连接解除绑定数据
     * @param $fd
     */
    public function unBind($fd)
    {
        $serverFd   = $this->getServerFd($fd);
        $sfdBindKey = $this->getSfdBindKey($serverFd);
        //获取serverFd主绑定数据
        $sfdBind = $this->redis->get($sfdBindKey);
        $sfdBind = json_to_array($sfdBind);
        if (!empty($sfdBind)) {
            //删除accountId主绑定；当前token
            $accountIdBindKey = $this->getAccountIdBindKey($sfdBind['accountId'] ?? 0);
            $this->redis->hDel($accountIdBindKey, $sfdBind['token'] ?? '');
        }
        //删除serverFd主绑定数据
        $this->redis->del($sfdBindKey);
    }

    /**
     * accountId主绑定token=>serverFd列表
     * @param $accountId
     * @return array
     */
    public function getAccountIdBind($accountId)
    {
        //ws绑定数据
        $accountIdBindKey = $this->getAccountIdBindKey($accountId);
        $data             = $this->redis->hGetAll($accountIdBindKey);
        $data             = is_array($data) ? $data : [];
        return $data;
    }

    /**
     * 删除accountId主绑定token=>serverFd列表项
     * @param $accountId
     * @return int
     */
    public function delAccountIdBind($accountId, $token)
    {
        $accountIdBindKey = $this->getAccountIdBindKey($accountId);
        return $this->redis->hDel($accountIdBindKey, $token);
    }

    public function getServerFd($fd)
    {
        $serverFd       = [];
        $serverFd['sv'] = get_inject_obj(WsServerComponent::class)->getServerKey();
        $serverFd['fd'] = $fd;
        return $serverFd;
    }

    private function getSfdBindKey(array $serverFd)
    {
        return WsKeys::bindSfdKey($serverFd['sv'], $serverFd['fd']);
    }

    private function getAccountIdBindKey($accountId)
    {
        return WsKeys::bindDimKey('account_id', $accountId);
    }
}
