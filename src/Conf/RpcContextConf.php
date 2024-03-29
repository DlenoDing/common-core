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
    const CLIENT_VERSION = '_CLIENT_VERSION_';//客户端版本
    const CLIENT_DEVICE  = '_CLIENT_DEVICE_';//设备号
    const MANAGER_ID     = '_MANAGER_ID_';//管理员ID

    const PER_PAGE = '_PER_PAGE_';//分页-每页条数
    const PAGE     = '_PAGE_';//分页-每页条数

}
