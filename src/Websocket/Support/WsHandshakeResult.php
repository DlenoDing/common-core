<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 中置握手钩子 WsHook::onHandshake 的返回值：把"(可能改写过的) request"和"解析出的完整身份"一起带回。
 *
 * 设计意图（防错包）：业务只负责"解析并返回身份"这件纯业务事；
 * "把身份写进 WsContext（WsIdentity::set）"这步基建动作由握手中间件统一执行，业务碰不到、也不可能漏写
 * —— 杜绝"忘了 WsIdentity::set → setBind 拿不到身份 → 绑定链静默失效"这一最难排查的故障。
 *
 * - $request：钩子里可对其 withHeader/withAttribute 改写，返回后由中间件写入 Context/WsContext、并继续握手链。
 * - $identity：resolver 的完整返回（建议并入 token）；空数组 = 匿名连接（中间件仍会 set，但 setBind 守卫为空则不绑）。
 */
final class WsHandshakeResult
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly array $identity = [],
    ) {
    }
}
