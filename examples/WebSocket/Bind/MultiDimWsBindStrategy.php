<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Bind;

use Dleno\CommonCore\Websocket\Strategy\AbstractWsBindStrategy;

/**
 * 多维度绑定策略示例。
 *
 * 设计要点:
 * - account_id: 高基数、单值连接数可控,适合推送寻址和心跳在线检查。
 * - device_type: 低基数(android/ios/h5),可用于按设备类型群推,但不适合在线检查。
 * - login: account_id:device_type 组合唯一维度,用于同账号同设备踢旧。
 */
class MultiDimWsBindStrategy extends AbstractWsBindStrategy
{
    public function bindDimensions(int $fd, array $identity): array
    {
        $accountId  = $identity['account_id'] ?? 0;
        $deviceType = (string) ($identity['device_type'] ?? 'unknown');

        return [
            'account_id'  => $accountId,
            'device_type' => $deviceType,
            'login'       => $accountId . ':' . $deviceType,
        ];
    }

    public function addressableDimensions(): array
    {
        // device_type 可用于 pushToDimMessage('device_type','ios',...) 做分组推送。
        return ['account_id', 'device_type', 'login'];
    }

    public function onlineCheckDimensions(): array
    {
        // 不放 device_type:低基数 value 可能挂海量连接,会拖垮在线检查。
        return ['account_id'];
    }

    public function uniqueDimensions(): array
    {
        // 同账号同设备只保留一条连接;如果要账号全局单连接,改为 ['account_id']。
        return ['login'];
    }
}
