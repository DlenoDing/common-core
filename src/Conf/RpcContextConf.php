<?php

namespace Dleno\CommonCore\Conf;

/**
 * RPC上下文配置
 */
class RpcContextConf
{
    const IN_RPC_SERVER = '__IN_RPC_SERVER__';//是否在rpc服务内
    const TRACE_ID      = '_TRACE_ID_';//请求跟踪号
    const LANGUAGE      = '_LANGUAGE_';//语言
    const TIMEZONE      = '_TIMEZONE_';//时区

    const CLIENT_IP      = '_CLIENT_IP_';//客户端IP
    const CLIENT_DEVICE  = '_CLIENT_DEVICE_';//设备号
    //注:业务专属的 RPC 上下文(如 管理员ID、客户端版本)不在此定义，由业务方自定义中间件读 header 后 rpc_context_set。

    const PER_PAGE = '_PER_PAGE_';//分页-每页条数
    const PAGE     = '_PAGE_';//分页-每页条数

}
