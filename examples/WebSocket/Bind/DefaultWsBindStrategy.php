<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Bind;

use Dleno\CommonCore\Websocket\Strategy\AbstractWsBindStrategy;

/**
 * WS 默认绑定策略示例:按 account_id 绑定、寻址、心跳在线检查。
 *
 * 复制到业务项目后,在 config/autoload/dependencies.php 绑定到 WsBindStrategyInterface。
 */
class DefaultWsBindStrategy extends AbstractWsBindStrategy
{
    public function bindDimensions(int $fd, array $identity): array
    {
        return [
            'account_id' => $identity['account_id'] ?? 0,
        ];
    }

    public function addressableDimensions(): array
    {
        return ['account_id'];
    }

    public function onlineCheckDimensions(): array
    {
        return ['account_id'];
    }
}
