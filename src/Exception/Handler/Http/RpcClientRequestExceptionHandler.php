<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Context\Context;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\JsonRpc\ResponseBuilder;
use Hyperf\RpcClient\Exception\RequestException;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\AppException;
use Dleno\CommonCore\Tools\OutPut;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class RpcClientRequestExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
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
        if (Context::get(RequestConf::OUTPUT_HTML)) {
            $output   = $message;
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } else {
            $output = OutPut::outJsonToError($message, $code);
        }
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
