<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Hook;

use Dleno\CommonCore\Websocket\Contract\WsHookInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 组合多个 WsHookInterface 子项（单一注入 + 内部 fan-out）。
 * 让日志 / presence / 风控 等独立关注点各自成类、互不耦合；注入时组进本类即可。
 * beforeSend 在子项间链式传递（前一个的输出是后一个的输入）。
 */
class CompositeWsHook extends AbstractWsHook
{
    /** @var WsHookInterface[] */
    private array $hooks;

    /**
     * @param WsHookInterface[] $hooks
     */
    public function __construct(array $hooks = [])
    {
        $this->hooks = $hooks;
    }

    public function add(WsHookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }

    public function beforeHandshake(ServerRequestInterface $request): void
    {
        foreach ($this->hooks as $h) { $h->beforeHandshake($request); }
    }

    public function onHandshake(ServerRequestInterface $request): ServerRequestInterface
    {
        //链式：前一个的(改过的)request 传给后一个；任一抛异常=拒绝握手
        foreach ($this->hooks as $h) { $request = $h->onHandshake($request); }
        return $request;
    }

    public function afterHandshake(ServerRequestInterface $request, array $identity): void
    {
        foreach ($this->hooks as $h) { $h->afterHandshake($request, $identity); }
    }

    public function beforeOpen($server, $request): void
    {
        foreach ($this->hooks as $h) { $h->beforeOpen($server, $request); }
    }

    public function afterOpen($server, $request): void
    {
        foreach ($this->hooks as $h) { $h->afterOpen($server, $request); }
    }

    public function beforeClose($server, int $fd): void
    {
        foreach ($this->hooks as $h) { $h->beforeClose($server, $fd); }
    }

    public function afterClose($server, int $fd): void
    {
        foreach ($this->hooks as $h) { $h->afterClose($server, $fd); }
    }

    public function beforeHeartbeat($server, $frame): void
    {
        foreach ($this->hooks as $h) { $h->beforeHeartbeat($server, $frame); }
    }

    public function afterHeartbeat($server, $frame): void
    {
        foreach ($this->hooks as $h) { $h->afterHeartbeat($server, $frame); }
    }

    public function beforeMessage($server, $frame, array $parsed): void
    {
        foreach ($this->hooks as $h) { $h->beforeMessage($server, $frame, $parsed); }
    }

    public function beforeSend($server, int $fd, string $payload): string
    {
        foreach ($this->hooks as $h) { $payload = $h->beforeSend($server, $fd, $payload); }
        return $payload;
    }

    public function afterMessage($server, $frame, $result): void
    {
        foreach ($this->hooks as $h) { $h->afterMessage($server, $frame, $result); }
    }
}
