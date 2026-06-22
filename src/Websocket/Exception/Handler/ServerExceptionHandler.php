<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Dleno\CommonCore\Websocket\Annotation\WsExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ServerExceptionHandler
 * @package Dleno\CommonCore\Websocket\Exception\Handler
 */
class ServerExceptionHandler extends ExceptionHandler
{
    use WsErrorResponder;

    /**
     * @param ServerException $throwable
     * @param ResponseInterface $response
     * @return mixed
     */
    #[WsExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $code = $throwable->getCode();
        $code = $code ?: RcodeConf::ERRNO_NORMAL;

        //数据返回
        return $this->respond($response, 'System Error', $code, 200, $throwable->getThrowData(), $throwable->getThrowTrace());
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ServerException;
    }
}
