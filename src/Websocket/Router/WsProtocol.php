<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Router;

use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;

/**
 * WS 帧协议编解码（固定契约，归包锁死，不对外开放为业务钩子）。
 *
 * 入站帧：{"reqId":..., "action":"a.b.ctrl.method", "params":{...}}
 * 出站帧（回包）：归一化信封 {"reqId":..., "code":int, "msg":string, "trace":[], "data":{...}}
 *
 * 协议即统一契约：各 app 自管编解码会导致跨端不一致/错包，故必须收敛进包并锁死（见方案 §0.5/§7.8）。
 * 业务对出站的观察/改写走 WsHookInterface::beforeSend（自担协议责任），而非改本类。
 */
class WsProtocol
{
    /**
     * 解码入站帧并校验。
     * @param string $raw 原始帧文本
     * @return array|false 合法 → ['reqId'=>, 'action'=>string, 'params'=>array]；非法 → false（上层静默丢弃）
     */
    public static function decode($raw)
    {
        $data = trim((string)$raw, "\r");
        $data = json_to_array($data);
        if (!is_array($data) || !isset($data['reqId']) || !isset($data['action'])) {
            return false;
        }

        if (!is_string($data['reqId']) && !is_numeric($data['reqId'])) {
            return false;
        }

        //action 必须是字符串：parseAction(string) 为强类型且在 try 外调用，
        //非字符串(数组/数字/对象)会抛未捕获的 TypeError 直接崩处理流程，这里前置拦截、静默丢弃。
        if (!is_string($data['action'])) {
            return false;
        }

        //action 字符白名单：仅允许 词字符段 用点分隔(如 test.test.index)，且至少 2 段(ctrl.method)。
        //拒绝含 反斜杠/斜杠/空段/空白 的 action —— 这些会让 parseAction 拼出的类名脱离
        //getControllerNamespace() 锁定的 App\WebSocket\Controller\ 之下(命名空间锁加固),静默丢弃。
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)+$/', $data['action'])) {
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
     * 编码出站回包（归一化信封）。
     * @param mixed $reqId
     * @param mixed $data 控制器返回 / 异常处理器产出（可能是 JSON 字符串 / 数组 / ResponseInterface）
     * @return string 序列化后的帧文本
     */
    public static function encodeReply($reqId, $data): string
    {
        return array_to_json(self::formatResponse($reqId, $data));
    }

    /**
     * 归一化出站信封：把控制器/异常处理器的各种返回形态统一成
     * {reqId, code, msg, trace, data}，并保证 data 恒为 JSON 对象（避免裸数组/双重编码）。
     * @param mixed $reqId
     * @param mixed $data
     * @return array
     */
    public static function formatResponse($reqId, $data): array
    {
        //ResponseInterface → 取 body 文本
        if ($data instanceof ResponseInterface) {
            $data = $data->getBody()->getContents();
        }

        //字符串(框架/异常处理器常产出 JSON 字符串) → 还原为数组，避免再被当字符串二次编码
        if (is_string($data)) {
            $decoded = json_to_array($data);
            $data    = is_array($decoded) ? $decoded : ['value' => $data];
        }

        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        $response = [
            'reqId' => (string)$reqId,
            'code'  => (int)($data['code'] ?? RcodeConf::SUCCESS),
            'msg'   => (string)($data['msg'] ?? ''),
            'trace' => (array)($data['trace'] ?? []),
            'data'  => $data['data'] ?? $data,
        ];

        //data 归一化为对象：标量 → {value}；空数组 → {}；纯列表 → {list:[...]}；关联数组原样。
        if (!is_array($response['data'])) {
            $response['data'] = ['value' => $response['data']];
        } elseif (array_is_list($response['data'])) {
            $response['data'] = $response['data'] === [] ? new \stdClass() : ['list' => $response['data']];
        }

        return $response;
    }
}
