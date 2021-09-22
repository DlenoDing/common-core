<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler;

use Hyperf\DbConnection\Db;
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
        if (class_exists('Dleno\\RpcTcc\\Transaction')) {
            Transaction::commonRollBack();
        } else {
            var_dump('no');
            if (Db::transactionLevel() > 0) {
                //首次beginTransaction为开始一个事务，后续的每次调用beginTransaction为创建事务保存点。
                //rollBack回滚也只是回滚到上一个保存点，并不是回滚整个事务
                Db::rollBack(0);//回滚整个事务
            }
        }
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
