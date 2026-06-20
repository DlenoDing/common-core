<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Hyperf\WebSocketServer\Context as WsContext;

/**
 * 当前 WS 连接的「完整身份」载体（per-fd，存活于握手→onOpen→onMessage→onClose 全程）。
 *
 * 解决：WsIdentityResolver::resolveByToken() 返回的是完整身份（account_id + 业务字段，如 account_type/device…），
 * 但握手中间件只把 account_id 写进了 header；若 setBind 仅从 header 重建身份，bindDimensions 就拿不到 resolver 的其它字段，
 * 业务方也就无法据 resolver 返回定义自定义维度。
 *
 * 约定：鉴权中间件解析出身份后调 WsIdentity::set($identity)（建议把 token 一并并入）；
 * WsTokenComponent::setBind 用 WsIdentity::get() 取完整身份传给 WsBindStrategy::bindDimensions。
 * 存于 WsContext，与该 fd 同生命周期。
 */
class WsIdentity
{
    const CTX_KEY = 'ws.identity';

    public static function set(array $identity): void
    {
        WsContext::set(self::CTX_KEY, $identity);
    }

    /**
     * @return array 当前连接完整身份；未设置则空数组
     */
    public static function get(): array
    {
        if (class_exists(WsContext::class) && WsContext::has(self::CTX_KEY)) {
            $identity = WsContext::get(self::CTX_KEY);
            return is_array($identity) ? $identity : [];
        }
        return [];
    }
}
