<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Websocket;

use Hyperf\Validation\ValidationException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ValidationExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Websocket
 */
class ValidationExceptionHandler extends \Hyperf\Validation\ValidationExceptionHandler
{
    /**
     * Handle the exception, and return the specified result.
     * @ExceptionHandlerLog()
     * @param ValidationException $throwable
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();
        /** @var ValidationException $throwable */
        $message = $throwable->validator->errors()
                                        ->first();
        $output  = OutPut::outJsonToError($message, RcodeConf::ERRNO_PARAMS);
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
        return $throwable instanceof ValidationException;
    }
}
