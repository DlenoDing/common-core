<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\OutPut;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 异常 Handler 的统一出参逻辑（OUTPUT_HTML / JSON 分支 + SwooleStream 写体）。
 *
 * 6 个 Http handler(Default/App/Http/Server/Validation/RpcClientRequest)继承了 3 个不同的
 * Hyperf 父类、没有共同项目基类,故用 trait 而非基类来消除「OUTPUT_HTML 分支逐字复制」的重复:
 * 各 handler 只负责算出 message/code/status/data/trace,出参与写体统一收敛到此处。
 */
trait HttpErrorResponder
{
    /**
     * 按当前请求的输出模式(OUTPUT_HTML=纯文本 / 否则 JSON)写入响应体。
     * JSON 分支保持惰性:仅非 HTML 模式才构造 outJsonToError(避免 HTML 模式下白做)。
     *
     * @param int   $status HTTP 状态码(须 100–599;由各 handler 自行保证/clamp)
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
        if (Context::get(RequestConf::OUTPUT_HTML)) {
            $output   = $message;
            $response = $response->withoutHeader('Content-Type')
                                 ->withHeader('Content-Type', 'text/html; charset=utf-8');
        } else {
            $output = OutPut::outJsonToError($message, $code, $data, $trace);
        }
        return $response->withStatus($status)->withBody(new SwooleStream($output));
    }
}
