<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Server;

use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Server;
use Dleno\CommonCore\Websocket\Contract\WsHookInterface;
use Dleno\CommonCore\Websocket\Support\WsIdentity;
use Dleno\CommonCore\Websocket\Support\WsOutLog;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\WebSocketServer\Context as WsContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Config\config;

/**
 * WS 握手中间件（纯基建，归包锁死）。
 *
 * 通用骨架：固定时区、运行时统计、traceId、Context/WsContext 写入、HandShake 日志；
 * 业务定制全部走三段钩子（依次 before → on → after），握手核心逻辑收敛进包、外部不可改坏：
 *  - beforeHandshake($request)                 鉴权之前：风控/灰度；抛异常=拒绝握手。
 *  - onHandshake($request): WsHandshakeResult  **中置**：业务身份解析（读 token→身份）、可改 header；抛异常=拒绝握手。
 *                                              只返回 (改过的)request + 完整身份；**WsIdentity::set 由本中间件统一执行**
 *                                              （供 setBind→bindDimensions）—— 业务不写这步、也不会漏写而致绑定链静默失效。
 *                                              业务在 AppWsHook::onHandshake 里实现。
 *  - afterHandshake($request, $identity)       身份已 set + Context 已写之后：埋点/presence 准备等。
 *
 * 接入：app 的 config/autoload/middlewares.php 的 ws 段引用本类即可，无需在项目里再建握手中间件。
 */
class WebSocketAuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected WsHookInterface $wsHook;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        //服务器固定时区运行
        date_default_timezone_set(config('app.default_time_zone', 'Asia/Shanghai'));

        //--------记录运行时间和内存占用情况--------
        Context::set(RequestConf::REQUEST_RUN_START, microtime(true));
        Context::set(RequestConf::REQUEST_RUN_MEM, memory_get_usage());
        //请求号(取时自动生成)
        Server::getTraceId();

        //前置钩子(默认 no-op;风控/灰度,抛异常=拒绝握手)
        $this->wsHook->beforeHandshake($request);
        //中置钩子(业务身份解析:读 token→身份、改 header;抛异常=拒绝握手;返回 改过的 request + 完整身份)
        $result   = $this->wsHook->onHandshake($request);
        $request  = $result->request;
        //身份入 WsContext 由本中间件统一执行(业务不写,杜绝漏写致绑定链静默失效);空身份=匿名连接,setBind 自会守卫不绑
        WsIdentity::set($result->identity);

        //仅 Open 使用 / 后续该 fd 全局使用
        Context::set(ServerRequestInterface::class, $request);
        WsContext::set(ServerRequestInterface::class, $request);

        //后置钩子(默认 no-op;身份(WsIdentity)已 set + Context 已写)
        $this->wsHook->afterHandshake($request, $result->identity);

        //接口输出日志
        WsOutLog::writeLog('HandShake', 'WS-RESPONSE');
        return $handler->handle($request);
    }
}
