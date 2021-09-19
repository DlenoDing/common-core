<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Websocket;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\Language;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class HttpExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Websocket
 */
class HttpExceptionHandler extends \Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler
{
    /**
     * @ExceptionHandlerLog()
     * Handle the exception, and return the specified result.
     * @param HttpException $throwable
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $message = $throwable->getMessage();
        $message = $message ? Language::get($message) : '';
        $code    = $throwable->getCode();
        $code    = $code ?: RcodeConf::ERROR_SERVER;

        //错误消息放头里，客户端获取
        return $response->withStatus($code)
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
