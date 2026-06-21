<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class DefaultExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class DefaultExceptionHandler extends ExceptionHandler
{
    use HttpErrorResponder;

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
        return $this->respond($response, 'Internal Server Error', RcodeConf::ERROR_SERVER, RcodeConf::ERROR_SERVER);
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
