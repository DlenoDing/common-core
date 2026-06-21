<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\Language;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class HttpExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class HttpExceptionHandler extends \Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler
{
    use HttpErrorResponder;

    /**
     * Handle the exception, and return the specified result.
     * @param HttpException $throwable
     */
    #[ExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $message    = $throwable->getMessage();
        $message    = $message?Language::get($message):'';
        $code       = $throwable->getCode();
        $code       = $code?:RcodeConf::ERROR_SERVER;

        //HTTP status 必须是合法状态码(100–599);若业务误用 HttpException 带越界码(如业务码 4001 / <100),
        //Swoole 发送端会产出损坏响应(客户端收到 -3 重置、空 body)→ 越界时 status 回退 500,
        //body 仍保留原 $code(含业务码),既给干净错误响应、又不丢业务码语义。
        $status = ($code >= 100 && $code <= 599) ? (int) $code : RcodeConf::ERROR_SERVER;

        return $this->respond($response, $message, (int) $code, $status);
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
