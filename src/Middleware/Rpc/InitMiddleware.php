<?php
declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Rpc;

use Dleno\CommonCore\Conf\RpcContextConf;
use Hyperf\Context\Context;
use Dleno\CommonCore\Conf\RequestConf;
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
 * @package Dleno\CommonCore\Middleware\Rpc
 */
class InitMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Context::set(RpcContextConf::IN_RPC_SERVER, true);
        //服务器固定时区运行
        date_default_timezone_set(config('app.default_time_zone', 'Asia/Shanghai'));
        #--------记录运行时间和内存占用情况--------
        $runStart  = microtime(true);
        $runMem    = memory_get_usage();
        Context::set(RequestConf::REQUEST_RUN_START, $runStart);
        Context::set(RequestConf::REQUEST_RUN_MEM, $runMem);

        #--------请求号，用于标识每个请求---------
        $traceId = Server::getTraceId();//获取时自动生成

        //-------设置时区---------
        //print_r(timezone_identifiers_list());
        $timezone = Client::getTimezone();
        Context::set(RequestConf::REQUEST_TIMEZONE, $timezone);

        //-------语言设置---------
        $language = Client::getLang();
        //有指定语言则设置
        Language::setLang($language);

        return $handler->handle($request);
    }
}
