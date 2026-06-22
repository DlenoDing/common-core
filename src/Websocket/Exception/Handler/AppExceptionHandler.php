<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Dleno\CommonCore\Websocket\Annotation\WsExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\AppException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class AppExceptionHandler
 * @package Dleno\CommonCore\Websocket\Exception\Handler
 */
class AppExceptionHandler extends ExceptionHandler
{
    use WsErrorResponder;

    /**
     * Handle the exception, and return the specified result.
     * @param AppException $throwable
     * @param ResponseInterface $response
     */
    #[WsExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $message = $throwable->getMessage();
        $code    = $throwable->getCode();
        $code    = $code ?: RcodeConf::ERRNO_NORMAL;

        //数据返回
        return $this->respond($response, $message, $code, 200, $throwable->getThrowData(), $throwable->getThrowTrace());
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
