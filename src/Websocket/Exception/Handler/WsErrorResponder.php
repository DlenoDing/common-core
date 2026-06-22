<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Exception\Handler;

use Dleno\CommonCore\Tools\OutPut;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;

/**
 * WS 异常 Handler 的统一出参逻辑(JSON 写体)。
 *
 * 对应 HTTP 侧的 {@see \Dleno\CommonCore\Exception\Handler\Http\HttpErrorResponder}:WS 的写体 handler
 * (App/Server/Default/Validation/RpcClientRequest)各自继承不同 Hyperf 父类、无共同项目基类,
 * 故同样用 trait 消除「outJsonToError + SwooleStream 写体」逐字复制。各 handler 只算 message/code/status/data/trace。
 * 注:WS 始终 JSON(无 HTTP 侧的 OUTPUT_HTML 纯文本分支);握手用的 HttpExceptionHandler 走 header 无 body,不在此列。
 */
trait WsErrorResponder
{
    /**
     * @param int   $code   业务错误码(写入 JSON body)
     * @param int   $status HTTP 状态码(须 100–599;缺省 200,由各 handler 自行保证/clamp)
     * @param array $data   业务附加数据(对应 outJsonToError 的 $data,缺省 [])
     * @param array $trace  调试 trace(对应 outJsonToError 的 $trace,缺省 [])
     */
    protected function respond(
        ResponseInterface $response,
        string $message,
        int $code,
        int $status = 200,
        array $data = [],
        array $trace = []
    ): ResponseInterface {
        $output = OutPut::outJsonToError($message, $code, $data, $trace);
        return $response->withStatus($status)->withBody(new SwooleStream($output));
    }
}
