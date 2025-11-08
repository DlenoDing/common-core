<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Context\Context;
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
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class HttpExceptionHandler extends \Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler
{
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

        if (Context::get(RequestConf::OUTPUT_HTML)) {
            $output   = $message;
            $response = $response->withoutHeader('Content-Type')
                                 ->withHeader('Content-Type', 'text/html; charset=utf-8');
        } else {
            $output = OutPut::outJsonToError($message, $code);
        }

        return $response->withStatus($code)->withBody(new SwooleStream($output));
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
