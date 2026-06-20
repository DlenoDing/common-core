<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Server;

use Dleno\CommonCore\Websocket\Component\WsServerComponent;
use Dleno\CommonCore\Websocket\Component\WsTokenComponent;
use Dleno\CommonCore\Websocket\Contract\WsHookInterface;
use Dleno\CommonCore\Websocket\Router\WsMessageRouter;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Di\Annotation\Inject;
use Swoole\Websocket\Frame;

use function Hyperf\Config\config;

/**
 * WS 服务入口（onOpen/onClose/onMessage 三事件分发，纯基建，归包锁死）。
 *
 * 连接建立/关闭/心跳的注册-绑定编排、心跳 ping/pong 协议、业务消息路由分发全部封存包内——
 * 这些每个项目都一样、没有自定义价值。业务的唯一注入点是生命周期钩子（WsHookInterface，默认 no-op）：
 *   beforeOpen/afterOpen、beforeClose/afterClose、beforeHeartbeat/afterHeartbeat。
 * 业务消息进入 WsMessageRouter（协议/路由亦归包）。
 *
 * 接入：在 app 的 config/routes.php 里
 *   Router::addServer('ws', fn() => Router::get('/', \Dleno\CommonCore\Websocket\Server\WebSocketEnter::class));
 * 即可，无需在项目里再建 Enter/Open/Close/Heartbeat 类。
 */
class WebSocketEnter implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    #[Inject]
    protected WsHookInterface $wsHook;

    #[Inject]
    protected WsMessageRouter $router;

    /**
     * 打开连接（握手成功之后）：注册 + 绑定 + 钩子。
     * @param \Swoole\Http\Response|\Swoole\WebSocket\Server $server
     * @param \Swoole\Http\Request $request
     */
    public function onOpen($server, $request): void
    {
        //服务器固定时区运行
        date_default_timezone_set(config('app.default_time_zone', 'Asia/Shanghai'));

        //前置钩子(默认 no-op)
        $this->wsHook->beforeOpen($server, $request);

        //同步执行:确保握手后客户端立即发来的消息能读到已完成的绑定数据(裸 go() 异步会产生绑定竞态)
        get_inject_obj(WsServerComponent::class)->registerClient($request->fd);
        get_inject_obj(WsTokenComponent::class)->setBind($request->fd);

        //后置钩子(默认 no-op;此刻已注册+绑定→业务可安全 push:欢迎语/上线广播)
        $this->wsHook->afterOpen($server, $request);
    }

    /**
     * 关闭连接：解绑 + 注销 + 钩子。
     * @param \Swoole\Http\Response|\Swoole\Server $server
     */
    public function onClose($server, int $fd, int $reactorId): void
    {
        date_default_timezone_set(config('app.default_time_zone', 'Asia/Shanghai'));

        //前置钩子(默认 no-op;此刻绑定仍在→业务可取身份做下线广播)
        $this->wsHook->beforeClose($server, $fd);

        //同步执行(裸 go() 会丢父协程 Context;且 swoole.use_shortname=Off 时 go() 未定义会崩 worker)
        get_inject_obj(WsServerComponent::class)->delClient($fd);
        get_inject_obj(WsTokenComponent::class)->unBind($fd);

        //后置钩子(默认 no-op;绑定已删,如需身份应在 beforeClose 捕获)
        $this->wsHook->afterClose($server, $fd);
    }

    /**
     * 消息接收：协议级/文本级心跳就地应答，业务消息交路由引擎。
     * @param \Swoole\Http\Response|\Swoole\WebSocket\Server $server
     * @param Frame $frame
     */
    public function onMessage($server, $frame): void
    {
        date_default_timezone_set(config('app.default_time_zone', 'Asia/Shanghai'));

        //协议级 Ping 帧 → 回复协议级 Pong
        if ($frame->opcode === WEBSOCKET_OPCODE_PING) {
            $pongFrame         = new Frame();
            $pongFrame->opcode = WEBSOCKET_OPCODE_PONG;
            $server->push($frame->fd, $pongFrame);
            $this->heartbeat($server, $frame);
        //文本 "ping" 心跳(兼容浏览器等无法主动发协议 Ping 的客户端) → 回复文本 "pong"
        //严格 === 比较,避免业务消息内容恰为数字(如 "9")时被误判为心跳
        } elseif ($frame->data === 'ping') {
            $pongFrame         = new Frame();
            $pongFrame->opcode = WEBSOCKET_OPCODE_TEXT;
            $pongFrame->data   = 'pong';
            $server->push($frame->fd, $pongFrame);
            $this->heartbeat($server, $frame);
        //正常业务消息
        } else {
            $this->router->handle($server, $frame);
        }
    }

    /**
     * 心跳：续约注册 + 刷新绑定 + 钩子。
     * @param \Swoole\Http\Response|\Swoole\WebSocket\Server $server
     */
    protected function heartbeat($server, Frame $frame): void
    {
        //前置钩子(默认 no-op)
        $this->wsHook->beforeHeartbeat($server, $frame);
        //续约客户端注册
        get_inject_obj(WsServerComponent::class)->registerClient($frame->fd);
        //刷新绑定数据过期时间
        get_inject_obj(WsTokenComponent::class)->refreshBind($frame->fd);
        //后置钩子(默认 no-op)
        $this->wsHook->afterHeartbeat($server, $frame);
    }
}
