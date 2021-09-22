<?php

namespace Dleno\CommonCore\Conf;

/**
 * rpc服务对应节点配置
 */
class RpcConsumersConf
{
    //是否使用注册中心
    const RPC_REGISTRY = false;
    //rpc服务对应访问节点
    public static $nodes = [
        //公共业务服务
        'Service.Account'     => [['port' => 9504, 'host' => 'Account.Rpc-Service']],
    ];

    //rpc服务本地开发调试对应访问节点
    public static $localNodes = [
        //公共业务服务
        'Service.Account'     => [['port' => 9601, 'host' => 'dev-app-api.xxxx.com']],
    ];
}
