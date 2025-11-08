<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Context\Context;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Tools\OutPut;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class DefaultExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class DefaultExceptionHandler extends ExceptionHandler
{
    /**
     * @param Throwable $throwable
     * @param ResponseInterface $response
     * @return mixed
     */
    #[ExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        //系统错误日志
        ErrorOutLog::writeLog($throwable, ErrorOutLog::LOG_ALERT);

        //数据返回
        $message = 'Internal Server Error';
        if (Context::get(RequestConf::OUTPUT_HTML)) {
            $output   = $message;
            $response = $response->withoutHeader('Content-Type')
                                 ->withHeader('Content-Type', 'text/html; charset=utf-8');
        } else {
            $output = OutPut::outJsonToError($message, RcodeConf::ERROR_SERVER);
        }
        return $response->withStatus(RcodeConf::ERROR_SERVER)
                        ->withBody(new SwooleStream($output));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
