<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Websocket;

use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Conf\RpcContextConf;
use Dleno\CommonCore\Contract\Websocket\WsHookInterface;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\ClassFunc\ClassRoute;
use Dleno\CommonCore\Tools\Server;
use Dleno\CommonCore\Tools\Websocket\WsProtocol;
use Hyperf\Di\Annotation\Inject;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\WebSocketServer\Exception\Handler\WebSocketExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Swoole\Websocket\Frame;
use Hyperf\Context\Context;
use Hyperf\WebSocketServer\Context as WsContext;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * WS 消息路由引擎（"WS-as-HTTP" 适配器，归包锁死）。
 *
 * 把一帧 WS 消息重建成 PSR-7 Request、伪造 Dispatched/Handler、调对应 Controller 方法、经 WS 异常处理链兜底、回包。
 * 协议(帧格式)+路由调度(action→Controller)+异常兜底全归包；深耦 Hyperf HTTP 内部(Dispatched/SwooleStream/中间件)，
 * 正因脆弱才集中维护——Hyperf 升级时一处定点修复，而非 N 个项目各自踩坑（见方案 §7.8）。
 *
 * 业务侧用空子类 extends 之（保留 onMessage 的注入点）。可控注入点仅三处钩子：
 * beforeMessage(进业务前风控/审计)、beforeSend(出站观察/改写,自担协议责任)、afterMessage(处理后埋点)。
 * 控制器命名空间定死（getControllerNamespace 默认 App\WebSocket\Controller\，防漂移；不给 config）。
 */
class WsMessageRouter
{
    #[Inject]
    protected ExceptionHandlerDispatcher $exceptionHandlerDispatcher;

    #[Inject]
    protected WsHookInterface $wsHook;

    /**
     * @var array
     */
    protected $exceptionHandlers;

    /**
     * 控制器命名空间根（定死，防漂移）。如确需变更走子类覆盖（非常规路径），不开放为 config。
     */
    protected function getControllerNamespace(): string
    {
        return 'App\\WebSocket\\Controller\\';
    }

    /**
     * 消息接收
     * @param \Swoole\Http\Response|\Swoole\WebSocket\Server $server
     * @param Frame $frame
     */
    public function handle($server, Frame $frame): void
    {
        //TODO 初始化
        $this->init();

        //TODO 检查数据格式(入站协议解码,归包)
        $frame->data = WsProtocol::decode($frame->data);
        if ($frame->data === false) {
            goto NOTRETURN;
        }
        //TODO 解析action
        $frame->data['action'] = $this->parseAction($frame->data['action']);

        //TODO 初始化Request（系统默认没有，做兼容模拟处理）
        $request = $this->initRequest($frame->data);

        $data = null;
        try {
            //前置钩子(默认 no-op;进业务前可做逐消息风控/频控/审计,抛异常→走下方异常回包)
            $this->wsHook->beforeMessage($server, $frame, $frame->data);

            //TODO 核心处理
            $this->coreHandle($request, $frame->data);

            //TODO 转换并检查路由
            $this->checkRoute($frame->data['action']);

            //TODO 调用对应Controller,获取返回数据
            $data = get_inject_obj($frame->data['action']['callback'][0])
                ->{$frame->data['action']['callback'][1]}();
        } catch (\Throwable $exception) {
            $data = null;
            $this->initResponse();
            /** @var ResponseInterface $psr7Response */
            $psr7Response = $this->exceptionHandlerDispatcher->dispatch($exception, $this->exceptionHandlers);
            if ($psr7Response instanceof ResponseInterface) {
                $data = $psr7Response->getBody()
                                     ->getContents();
            }
        }

        //回复消息到客户端-压缩支持
        if (!empty($data)) {
            //出站协议编码(归包)
            $return = WsProtocol::encodeReply($frame->data['reqId'], $data);
            //发送前钩子(默认 no-op;业务可观察/改写出站,自担协议责任)
            $return = $this->wsHook->beforeSend($server, (int)$frame->fd, $return);
            if (!env('WEBSOCKET_COMPRESSION', false)) {//这个配置无法通过配置中心来设置
                $server->push($frame->fd, $return);
            } else {
                $server->push(
                    $frame->fd,
                    $return,
                    SWOOLE_WEBSOCKET_OPCODE_TEXT,
                    SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
                );
            }
        }

        //后置钩子(默认 no-op;处理后埋点/日志)
        $this->wsHook->afterMessage($server, $frame, $data);

        NOTRETURN:
        //不发送任何数据
    }

    protected function coreHandle(ServerRequestInterface $request, $data)
    {
        //TODO Hyperf\HttpMessage\Server\Request::stream -> Hyperf\HttpMessage\Stream\SwooleStream
        $stream = new SwooleStream(array_to_json($data['params']));

        //更新对应数据到本次请求模拟的Request
        $request = $request->withBody($stream)
            //TODO Hyperf\HttpMessage\Server\Request::parsedBody
                           ->withParsedBody($data['params']);

        //保存Request上下文
        Context::set(ServerRequestInterface::class, $request);

        if (get_post_val('page')) {
            rpc_context_set(RpcContextConf::PAGE, (int)get_post_val('page'));//页码
        }
        if (get_post_val('perPage')) {
            rpc_context_set(RpcContextConf::PER_PAGE, (int)get_post_val('perPage'));//每页记录数
        }

        return $request;
    }

    protected function checkRoute(array $action)
    {
        $check = ClassRoute::checkExists($action['callback'][0], $action['callback'][1]);
        if (!$check) {
            throw new HttpException('NOT FOUND', RcodeConf::ERROR_NOTFOUND);
        }
    }

    protected function parseAction(string $action)
    {
        $route    = str_replace('.', '/', $action);
        $action   = explode('.', $action);
        $actionCt = count($action);

        $module = [];
        if ($actionCt > 2) {
            $module = array_slice($action, 0, $actionCt - 2);
            array_walk(
                $module,
                function (&$val) {
                    $val = ucfirst($val);
                }
            );
        }

        $ctrl        = get_array_val($action, $actionCt - 2, '');
        $callback[0] = $this->getControllerNamespace();
        if ($module) {
            $callback[0] .= join('\\', $module) . '\\';
        }
        $callback[0] .= ucfirst($ctrl) . 'Controller';
        $callback[1] = get_array_val($action, $actionCt - 1, '');
        return [
            'callback' => $callback,
            'route'    => '/' . $route,
        ];
    }

    protected function init()
    {
        $this->exceptionHandlers = config(
            'exceptions.handler.ws',
            [
                WebSocketExceptionHandler::class,
            ]
        );
        //--------记录运行时间和内存占用情况--------
        $runStart = microtime(true);
        $runMem   = memory_get_usage();
        Context::set(RequestConf::REQUEST_RUN_START, $runStart);
        Context::set(RequestConf::REQUEST_RUN_MEM, $runMem);

        //--------请求号，用于标识每个请求---------
        Server::getTraceId();//获取时自动生成
    }

    /**
     * Initialize PSR-7 Request.
     * @return ServerRequestInterface
     */
    protected function initRequest($data): ServerRequestInterface
    {
        $request = clone(WsContext::get(ServerRequestInterface::class));

        //TODO Hyperf\HttpMessage\Server\Request::attributes[Dispatched] -> Hyperf\HttpServer\Router\Dispatched
        //这里只能新建Dispatched；不能直接$request->getAttribute(Dispatched::class)再修改，否则会影响握手的Request
        $routes     = [
            1,
            new Handler($data['action']['callback'], $data['action']['route']),
            [],
        ];
        $dispatched = new Dispatched($routes);

        //TODO Hyperf\HttpMessage\Server\Request::uri -> Hyperf\HttpMessage\Uri\Uri
        /** @var UriInterface $uri */
        $uri = $request->getUri()
                       ->withPath($data['action']['route'])
                       ->withQuery('');

        //更新对应数据到本次请求模拟的Request
        $request = $request->withMethod('POST')
                           ->withAttribute(Dispatched::class, $dispatched)
                           ->withUri($uri);

        //保存初始Request上下文
        Context::set(ServerRequestInterface::class, $request);

        return $request;
    }

    /**
     * Initialize PSR-7 Response.
     * @return ResponseInterface
     */
    protected function initResponse(): ResponseInterface
    {
        Context::set(ResponseInterface::class, $psr7Response = new Psr7Response());
        return $psr7Response;
    }
}
