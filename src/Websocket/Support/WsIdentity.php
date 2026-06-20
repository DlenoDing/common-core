<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Hyperf\WebSocketServer\Context as WsContext;

/**
 * 当前 WS 连接的「完整身份」载体（per-fd，存活于握手→onOpen→onMessage→onClose 全程）。
 *
 * 存的是业务在握手钩子里解析出的完整身份（account_id + 任意业务字段，如 account_type/device…），
 * 而非只有 header 里的 account_id —— 这样 setBind 才能把完整身份交给 WsBindStrategy::bindDimensions，
 * 业务方可据其中任意字段定义绑定维度。
 *
 * 流转：握手中间件取钩子返回的身份调 WsIdentity::set($identity)（已并入 token）；
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
