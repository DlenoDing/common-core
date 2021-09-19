<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Rpc;

use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Exception\AppException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class AppExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Rpc
 */
class AppExceptionHandler extends ExceptionHandler
{
    /**
     * @ExceptionHandlerLog()
     * Handle the exception, and return the specified result.
     * @param AppException $throwable
     * @param ResponseInterface $response
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        //此处写其他处理，但不要改变$response的内容，因为框架已自动处理了错误格式的输出，必须遵循框架的数据通信格式

        return $response;
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
