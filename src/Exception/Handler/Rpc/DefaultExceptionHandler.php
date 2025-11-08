<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Rpc;

use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class DefaultExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Rpc
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

        //此处写其他处理，但不要改变$response的内容，因为框架已自动处理了错误格式的输出，必须遵循框架的数据通信格式

        //系统错误日志
        ErrorOutLog::writeLog($throwable, ErrorOutLog::LOG_ALERT);

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
