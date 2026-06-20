<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Websocket;

use Dleno\CommonCore\Contract\Websocket\WsHookInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * WS 钩子默认实现：全 no-op（beforeSend 原样返回）。
 * - 作为 WsHookInterface 的默认 DI 绑定（业务不提供时零成本、零侵入）；
 * - 也作为业务钩子的基类（继承后只 override 需要的方法）。
 * 命名沿用方案文档；本类为具体类（可实例化）以充当默认绑定。
 */
class AbstractWsHook implements WsHookInterface
{
    public function beforeHandshake(ServerRequestInterface $request): void {}
    public function afterHandshake(ServerRequestInterface $request, array $identity): void {}

    public function beforeOpen($server, $request): void {}
    public function afterOpen($server, $request): void {}

    public function beforeClose($server, int $fd): void {}
    public function afterClose($server, int $fd): void {}

    public function beforeHeartbeat($server, $frame): void {}
    public function afterHeartbeat($server, $frame): void {}

    public function beforeMessage($server, $frame, array $parsed): void {}

    public function beforeSend($server, int $fd, string $payload): string
    {
        return $payload;
    }

    public function afterMessage($server, $frame, $result): void {}
}
