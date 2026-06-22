<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Strategy;

use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;

/**
 * WS 绑定策略抽象基类：提供可选方法的框架默认实现，业务策略继承本类即可，只需实现两个必填方法。
 *
 * - bindDimensions / addressableDimensions：业务**必须**实现（绑哪些维度、哪些可寻址）。
 * - uniqueDimensions：框架已给默认（返回 []，即同维度值可多连接）；要单连接（踢旧）再 override。
 *
 * 继承本类即天然向后兼容：日后契约再加可选方法，也只在本基类补默认、业务无感。
 */
abstract class AbstractWsBindStrategy implements WsBindStrategyInterface
{
    abstract public function bindDimensions(int $fd, array $identity): array;

    abstract public function addressableDimensions(): array;

    /**
     * 默认：无单连接维度（同维度值可挂多个连接）。需要单点登录/踢旧时 override，返回需唯一的维度名子集。
     * @return string[]
     */
    public function uniqueDimensions(): array
    {
        return [];
    }

    /**
     * 默认：无额外在线检查维度（此时仅 uniqueDimensions 维度可被在线检查——框架自动并入）。
     * 要让某非 unique 维度（如多端 account_id）可被 checkHeartbeatOnlineByDim 查询，override 本方法返回它。
     * @return string[]
     */
    public function onlineCheckDimensions(): array
    {
        return [];
    }
}
