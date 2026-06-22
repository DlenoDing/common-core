<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Exception\Handler;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Dleno\CommonCore\Websocket\Annotation\WsExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\Language;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class HttpExceptionHandler
 * @package Dleno\CommonCore\Websocket\Exception\Handler
 */
class HttpExceptionHandler extends \Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler
{
    /**
     * Handle the exception, and return the specified result.
     * @param HttpException $throwable
     */
    #[WsExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $message = $throwable->getMessage();
        $message = $message ? Language::get($message) : '';
        $code    = $throwable->getCode();
        $code    = $code ?: RcodeConf::ERROR_SERVER;

        //HTTP status 必须合法(100–599)。握手响应直接用 $code 作 status,若业务误用 HttpException 带越界码
        //(如业务码 4001 / <100),Swoole 发送端会产出损坏响应(客户端收到 -3 重置/空响应)→ 越界回退 500。
        $status = ($code >= 100 && $code <= 599) ? (int) $code : RcodeConf::ERROR_SERVER;

        //错误消息放头里，客户端获取
        return $response->withStatus($status)
                        ->withHeader('HandShake-Message', $message);
    }

    /**
     * Determine if the current exception handler should handle the exception,.
     *
     * @return bool
     *              If return true, then this exception handler will handle the exception,
     *              If return false, then delegate to next handler
     */
    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof HttpException;
    }
}
