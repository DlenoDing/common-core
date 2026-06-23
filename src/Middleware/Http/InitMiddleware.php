<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Hyperf\Context\Context;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Conf\RpcContextConf;
use Dleno\CommonCore\Tools\Client;
use Dleno\CommonCore\Tools\Language;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Config\config;

/**
 * 初始化处理中间件
 * Class InitMiddleware
 * @package App\Core\Middleware
 */
class InitMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Context::set(RequestConf::IN_HTTP_SERVER, true);

        //服务器固定时区运行
        date_default_timezone_set(config('app.default_time_zone', 'UTC'));

        //兜底:Context 里没有 ResponseInterface 时构造一个,避免下面 return $response / 后续使用拿到 null
        //(PSR-15 中间件必须返回 ResponseInterface,返 null 会 TypeError)。
        $response = Context::get(ResponseInterface::class) ?? new \Hyperf\HttpMessage\Server\Response();
        $servers  = $request->getServerParams();
        if (($servers['request_uri'] ?? '') == '/favicon.ico') {
            return $response;
        }
        //-------Header信息设置--------
        $allowHeaders = config('app.ac_allow_headers') ?? [
            "Content-Type",//请求内容类型
        ];
        //Access-Control-Allow-Methods 返回【该路径实际允许的请求方式】(查路由表)，而非静态全局配置。
        $allowMethods = $this->resolveAllowedMethods($request);
        $response     = $response
            ->withHeader('Server', config('app_name', 'MyServer'))
            // 设置返回数据格式及编码
            ->withoutHeader('Content-Type')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            // 跨域处理
            ->withHeader('Access-Control-Max-Age', '3600')
            ->withHeader('Access-Control-Allow-Methods', \join(',', $allowMethods))
            ->withHeader('Access-Control-Allow-Headers', \join(',', $allowHeaders));
        // 带凭证(Allow-Credentials:true)时 Allow-Origin 不能为 *(CORS 规范禁止，浏览器会拒绝响应)，
        // 必须回显具体 Origin；并加 Vary: Origin 防止共享缓存把某源的 ACAO 错发给其它源。
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && self::isAllowedOrigin($origin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }
        Context::set(ResponseInterface::class, $response);

        //-----------处理OPTIONS请求(预检)-----------
        //预检按惯例返回 204 No Content(无响应体),仅携带上面的 CORS 头
        if ($request->getMethod() == 'OPTIONS') {
            return $response->withStatus(204);
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

        //处理参数
        $this->initRequest();

        //业务专属上下文(如 管理员ID、客户端版本 等)由业务方在自定义中间件里读 header 自行 rpc_context_set，框架不内置：
        //  if (get_header_val('Client-ManagerId', 0)) { rpc_context_set('_MANAGER_ID_', (int)get_header_val('Client-ManagerId', '0')); }

        return $handler->handle($request);
    }

    public function initRequest()
    {
        if (get_post_val('page')) {
            rpc_context_set(RpcContextConf::PAGE, (int)get_post_val('page'));//页码
        }
        if (get_post_val('perPage')) {
            rpc_context_set(RpcContextConf::PER_PAGE, (int)get_post_val('perPage'));//每页记录数
        }
    }

    /**
     * 返回当前请求路径【实际允许的请求方式】，供 CORS Access-Control-Allow-Methods 头返回真实值。
     * 做法：用一个未注册的探针方法分发该路径——路径存在则 FastRoute 返回 METHOD_NOT_ALLOWED 并带回该路径
     * 已注册的全部方法（含框架为 GET 自动补的 HEAD）；路径未命中 / 异常 → 回退
     * config('app.default_allow_methods') → ['POST','GET']。
     * @return string[]
     */
    private function resolveAllowedMethods(ServerRequestInterface $request): array
    {
        try {
            $path       = $request->getUri()->getPath();
            $dispatcher = get_inject_obj(\Hyperf\HttpServer\Router\DispatcherFactory::class)->getDispatcher('http');
            $res        = $dispatcher->dispatch('__CORS_ALLOW_PROBE__', $path);
            if (($res[0] ?? null) === \FastRoute\Dispatcher::METHOD_NOT_ALLOWED && !empty($res[1]) && is_array($res[1])) {
                return array_values(array_unique(array_map('strtoupper', $res[1])));
            }
        } catch (\Throwable $e) {
            //查路由失败不影响请求，回退默认
        }
        $def = config('app.default_allow_methods');
        if (!is_array($def) || $def === []) {
            $def = ['POST', 'GET'];
        }
        return array_values(array_unique(array_map(fn ($m) => strtoupper(trim((string) $m)), $def)));
    }

    /**
     * 是否允许该跨域来源（用于回显 Access-Control-Allow-Origin）。
     * 规则：app.ac_allow_origins 为 '*' / 未设置 / 空 → 回显任意合法 http(s) 源；
     *       否则按白名单精确匹配（推荐在带 Allow-Credentials 时使用具体白名单）。
     * @param string $origin 请求头 Origin
     * @return bool
     */
    private static function isAllowedOrigin(string $origin): bool
    {
        //仅接受 http(s)://host 形式，排除 "null"、非法值等
        if (!preg_match('#^https?://[^/]+$#', $origin)) {
            return false;
        }
        $allow = config('app.ac_allow_origins', []);
        //未设置 / 空（null/''/[]）→ 回显任意（合法）源
        if (empty($allow)) {
            return true;
        }
        $allow = is_array($allow) ? $allow : [$allow];
        //'*' → 回显任意（合法）源
        if (in_array('*', $allow, true)) {
            return true;
        }
        //否则按白名单精确匹配
        return in_array($origin, $allow, true);
    }
}
