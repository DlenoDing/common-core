<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Strategy;

use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;

/**
 * 默认绑定策略：仅绑定 account_id 一个维度，按 account_id 可寻址（单端按 account_id 反查全部连接）。
 *
 * 反向索引以 sv:fd 作为每连接唯一 field，同账号多连接互不覆盖，无需再把 token 当维度。
 * 业务需要 token / device / 多端等维度时，实现自己的 WsBindStrategyInterface 并在 dependencies.php 覆盖绑定。
 */
class DefaultWsBindStrategy implements WsBindStrategyInterface
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
}
