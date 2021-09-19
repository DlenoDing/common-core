<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Websocket;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\JsonRpc\ResponseBuilder;
use Hyperf\RpcClient\Exception\RequestException;
use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\AppException;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Notice\DingDing;
use Dleno\CommonCore\Tools\OutPut;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class RpcClientRequestExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Websocket
 */
class RpcClientRequestExceptionHandler extends ExceptionHandler
{
    /**
     * @ExceptionHandlerLog()
     * Handle the exception, and return the specified result.
     * @param RequestException $throwable
     * @param ResponseInterface $response
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        //系统错误日志
        ErrorOutLog::writeLog($throwable, ErrorOutLog::LOG_ERROR);

        //var_dump($throwable->getThrowable());
        if ($throwable->getCode() == ResponseBuilder::SERVER_ERROR && $throwable->getThrowableClassName(
            ) == AppException::class) {
            $code    = $throwable->getThrowableCode();
            $code    = $code ?: RcodeConf::ERRNO_NORMAL;
            $message = $throwable->getThrowableMessage();
            $message = $message ?: 'System Error';
        } else {
            $code    = RcodeConf::ERRNO_NORMAL;
            $message = 'System Error';
        }

        //数据返回
        $output = OutPut::outJsonToError($message, $code);
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
        return $throwable instanceof RequestException;
    }
}
