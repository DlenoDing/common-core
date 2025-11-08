<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Context\Context;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\ServerException;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ServerExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class ServerExceptionHandler extends ExceptionHandler
{
    /**
     * @param ServerException $throwable
     * @param ResponseInterface $response
     * @return mixed
     */
    #[ExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $code = $throwable->getCode();
        $code = $code ?: RcodeConf::ERRNO_NORMAL;

        //数据返回
        $message = 'System Error';
        if (Context::get(RequestConf::OUTPUT_HTML)) {
            $output   = $message;
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } else {
            $output = OutPut::outJsonToError(
                $message,
                $code,
                $throwable->getThrowData(),
                $throwable->getThrowTrace()
            );
        }
        return $response->withStatus(200)
                        ->withBody(new SwooleStream($output));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ServerException;
    }
}
