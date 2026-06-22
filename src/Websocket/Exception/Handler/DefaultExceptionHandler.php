<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Dleno\CommonCore\Websocket\Annotation\WsExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class DefaultExceptionHandler
 * @package Dleno\CommonCore\Websocket\Exception\Handler
 */
class DefaultExceptionHandler extends ExceptionHandler
{
    use WsErrorResponder;

    /**
     * @param Throwable $throwable
     * @param ResponseInterface $response
     * @return mixed
     */
    #[WsExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        //系统错误日志
        ErrorOutLog::writeLog($throwable, ErrorOutLog::LOG_ERROR);

        //数据返回
        return $this->respond($response, 'Internal Server Error', RcodeConf::ERROR_SERVER, RcodeConf::ERROR_SERVER);
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
