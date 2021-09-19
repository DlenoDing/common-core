<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Hyperf\Utils\Context;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Conf\RpcContextConf;
use Dleno\CommonCore\Tools\Client;
use Dleno\CommonCore\Tools\Language;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 初始化处理中间件
 * Class InitMiddleware
 * @package App\Core\Middleware
 */
class InitMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        //服务器固定时区运行
        date_default_timezone_set(config('app.default_time_zone', 'UTC'));

        $response = Context::get(ResponseInterface::class);
        $servers  = $request->getServerParams();
        if (($servers['request_uri'] ?? '') == '/favicon.ico') {
            return $response;
        }
        //-------Header信息设置--------
        $allowHeaders = config('app.ac_allow_headers') ?? [
                "Content-Type",//请求内容类型
            ];
        $allowMethods = config('app.ac_allow_methods') ?? ['POST', 'GET', 'HEAD'];
        $response     = $response
            ->withHeader('Server', config('app_name', 'MyServer'))
            // 设置返回数据格式及编码
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            // 跨域处理
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '3600')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', \join(',', $allowMethods))
            ->withHeader('Access-Control-Allow-Headers', \join(',', $allowHeaders));
        Context::set(ResponseInterface::class, $response);

        //-----------处理OPTIONS请求-----------
        if ($request->getMethod() == 'OPTIONS') {
            return $response;
        }

        //--------记录运行时间和内存占用情况--------
        $runStart = microtime(true);
        $runMem   = memory_get_usage();
        Context::set(RequestConf::REQUEST_RUN_START, $runStart);
        Context::set(RequestConf::REQUEST_RUN_MEM, $runMem);

        //--------请求号，用于标识每个请求---------
        $traceId = Server::getTraceId();//获取时自动生成

        //-------设置时区---------
        $timezone = Client::getTimezone();
        Context::set(RequestConf::REQUEST_TIMEZONE, $timezone);

        //-------语言设置---------
        $language = Client::getLang();
        Language::setLang($language);

        //============其他需要同步到RPC服务的数据===============
        rpc_context_set(RpcContextConf::TRACE_ID, $traceId);//请求号
        rpc_context_set(RpcContextConf::TIMEZONE, $timezone);//时区
        rpc_context_set(RpcContextConf::LANGUAGE, $language);//语言
        rpc_context_set(RpcContextConf::CLIENT_IP, Client::getIP());//客户端IP
        rpc_context_set(RpcContextConf::CLIENT_DEVICE, Client::getDevice());//客户端设备号

        if (get_header_val('Client-Version', 0)) {
            rpc_context_set(RpcContextConf::CLIENT_VERSION, (int)get_header_val('Client-Version', 0));//客户端插件版本
        }
        if (get_header_val('Client-MerchantId', 0)) {
            rpc_context_set(RpcContextConf::MERCHANT_ID, (int)get_header_val('Client-MerchantId', 0));//客户端商家ID
        }
        if (get_header_val('Client-ManagerId', 0)) {
            rpc_context_set(RpcContextConf::MANAGER_ID, (int)get_header_val('Client-ManagerId', 0));//管理员ID
        }
        if (get_post_val('page')) {
            rpc_context_set(RpcContextConf::PAGE, (int)get_post_val('page'));//页码
        }
        if (get_post_val('perPage')) {
            rpc_context_set(RpcContextConf::PER_PAGE, (int)get_post_val('perPage'));//每页记录数
        }

        //其他RPC上下文写在对应项目的自定义中间件内
        //rpc_context_set('testKey', 'i am api');

        return $handler->handle($request);
    }
}
