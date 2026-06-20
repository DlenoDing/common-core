<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * WS 生命周期钩子（可控注入点）。
 * 握手 / open / close / heartbeat / message 各留前置+后置，message 多一个"发送前"。
 * 默认 AbstractWsHook 全 no-op；业务继承覆盖需要的方法并经 DI 注入；多关注点用 CompositeWsHook 组合。
 * 钩子是"副作用注入点"，改不到协议/路由；前置钩子可抛异常以受控否决。
 *
 * 类型说明：$server=Swoole WS Server；$request(open)=Swoole\Http\Request；$frame=Swoole\WebSocket\Frame；
 * $identity=resolver 返回数组；$parsed={reqId,action,params}；$result=Controller 返回/异常转换后的回包数据。
 */
interface WsHookInterface
{
    // —— 握手（WebSocketAuthMiddleware 内，依次 before → on → after）——
    public function beforeHandshake(ServerRequestInterface $request): void;        // 鉴权之前：风控/灰度；抛异常=拒绝握手
    public function onHandshake(ServerRequestInterface $request): ServerRequestInterface; // 中置：业务身份解析(读 token→身份)、可改 header、WsIdentity::set 完整身份；抛异常=拒绝握手；返回(可能改过的)request
    public function afterHandshake(ServerRequestInterface $request, array $identity): void; // 身份解析+写 Context 之后

    // —— open（WsOpen，onOpen 协程内）——
    public function beforeOpen($server, $request): void;                            // 注册/绑定之前
    public function afterOpen($server, $request): void;                             // 注册+绑定完成（可 push：欢迎/上线）

    // —— close（WsClose，onClose 内）——
    public function beforeClose($server, int $fd): void;                            // 解绑之前（身份仍在）
    public function afterClose($server, int $fd): void;                             // 解绑之后（清理）

    // —— heartbeat（WsHeartbeat）——
    public function beforeHeartbeat($server, $frame): void;
    public function afterHeartbeat($server, $frame): void;

    // —— message（WsMessageRouter）——
    public function beforeMessage($server, $frame, array $parsed): void;            // 调 Controller 前；抛异常=中止
    public function beforeSend($server, int $fd, string $payload): string;          // 回包 push 前；返回最终 payload（可改写）
    public function afterMessage($server, $frame, $result): void;                   // 处理后
}
