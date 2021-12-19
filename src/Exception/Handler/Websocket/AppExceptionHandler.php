<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Websocket;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Dleno\CommonCore\Annotation\WsExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\AppException;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class AppExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Websocket
 */
class AppExceptionHandler extends ExceptionHandler
{
    /**
     * @WsExceptionHandlerLog()
     * Handle the exception, and return the specified result.
     * @param AppException $throwable
     * @param ResponseInterface $response
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $message = $throwable->getMessage();
        $code    = $throwable->getCode();
        $code    = $code ?: RcodeConf::ERRNO_NORMAL;

        //数据返回
        $output = OutPut::outJsonToError($message, $code, $throwable->getThrowData(), $throwable->getThrowTrace());
        return $response->withStatus(200)
                        ->withBody(new SwooleStream($output));
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
        return $throwable instanceof AppException;
    }
}
