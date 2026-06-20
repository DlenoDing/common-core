<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Router;

use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Context\Context;

/**
 * WS 帧协议编解码（固定契约，归包锁死，不对外开放为业务钩子）。
 *
 * 入站帧：{"reqId":..., "action":"a.b.ctrl.method", "params":{...}}
 * 出站帧（回包）：{"reqId":..., "data":...}
 *
 * 协议即统一契约：各 app 自管编解码会导致跨端不一致/错包，故必须收敛进包并锁死（见方案 §0.5/§7.8）。
 * 业务对出站的观察/改写走 WsHookInterface::beforeSend（自担协议责任），而非改本类。
 */
class WsProtocol
{
    /**
     * 解码入站帧并校验。
     * @param string $raw 原始帧文本
     * @return array|false 合法 → ['reqId'=>, 'action'=>, 'params'=>array]；非法 → false（上层静默丢弃）
     */
    public static function decode($raw)
    {
        $data = trim((string)$raw, "\r");
        $data = json_to_array($data);
        if (!isset($data['reqId']) || !isset($data['action'])) {
            return false;
        }

        if (!is_string($data['reqId']) && !is_numeric($data['reqId'])) {
            return false;
        }
        $data['params'] = $data['params'] ?? [];

        if (!is_array($data['params'])) {
            $data['params'] = [];
        }

        //记录当前客户端请求ID
        Context::set(RequestConf::REQUEST_REQ_ID, $data['reqId']);

        return $data;
    }

    /**
     * 编码出站回包。
     * @param mixed $reqId
     * @param mixed $data 控制器返回（成功体 / 异常处理器产出的错误体）
     * @return string 序列化后的帧文本
     */
    public static function encodeReply($reqId, $data): string
    {
        return array_to_json(
            [
                'reqId' => $reqId,
                'data'  => $data,
            ]
        );
    }
}
