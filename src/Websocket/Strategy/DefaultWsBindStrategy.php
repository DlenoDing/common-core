<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Strategy;

use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;

/**
 * 默认绑定策略 = 脚手架现状：绑定 account_id + token，按 account_id 可寻址（单端按 account_id 反查全部连接）。
 * 业务需要多端/设备维度时，实现自己的 WsBindStrategyInterface 并在 dependencies.php 覆盖绑定。
 */
class DefaultWsBindStrategy implements WsBindStrategyInterface
{
    public function bindDimensions(int $fd, array $identity): array
    {
        return [
            'account_id' => $identity['account_id'] ?? 0,
            'token'      => $identity['token'] ?? '',
        ];
    }

    public function addressableDimensions(): array
    {
        return ['account_id'];
    }
}
