<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Dleno\RpcTcc\Transaction;
use Dleno\CommonCore\Exception\AppException;

/**
 * 公共异常控制器,不中断，做公共的异常处理
 * Class CommonExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler
 */
class CommonExceptionHandler extends ExceptionHandler
{
    /**
     * Handle the exception, and return the specified result.
     * @param AppException $throwable
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        //事务回滚
        Transaction::commonRollBack();
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
        return true;
    }
}
