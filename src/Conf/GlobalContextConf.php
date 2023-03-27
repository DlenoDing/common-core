<?php

namespace Dleno\CommonCore\Conf;

/**
 * 当前请求配置
 */
class GlobalContextConf
{
    //允许跨协程自动复制的context key(创建协程时会自动将当前协程对应的值复制到子协程)
    public static $globalContext = [
        //核心Request Response
        \Psr\Http\Message\ServerRequestInterface::class,
        \Psr\Http\Message\ResponseInterface::class,
        //语言包
        \Hyperf\Contract\TranslatorInterface::class . '::locale',
        //rpc上下文
        \Hyperf\Rpc\Context::class . '::DATA',
        //系统其它需要共享拷贝的上下文
        RpcContextConf::IN_RPC_SERVER,
        RequestConf::IN_HTTP_SERVER,
        RequestConf::REQUEST_TRACE_ID,
        RequestConf::REQUEST_RUN_START,
        RequestConf::REQUEST_RUN_MEM,
        RequestConf::REQUEST_TIMEZONE,
        RequestConf::REQUEST_MCA,
        RequestConf::REQUEST_REQ_ID,
        RequestConf::OUTPUT_NOT_FORMAT,
        RequestConf::OUTPUT_HTML,
        RequestConf::OUTPUT_NO_LOG,
    ];

}
