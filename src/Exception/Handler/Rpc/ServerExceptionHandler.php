<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Rpc;

use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Exception\ServerException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ServerExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Rpc
 */
class ServerExceptionHandler extends ExceptionHandler
{
    /**
     * @ExceptionHandlerLog()
     * @param ServerException $throwable
     * @param ResponseInterface $response
     * @return mixed
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        //此处写其他处理，但不要改变$response的内容，因为框架已自动处理了错误格式的输出，必须遵循框架的数据通信格式

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ServerException;
    }
}
