<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Dleno\CommonCore\Websocket\Annotation\WsExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\ServerException;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ServerExceptionHandler
 * @package Dleno\CommonCore\Websocket\Exception\Handler
 */
class ServerExceptionHandler extends ExceptionHandler
{
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
        $output = OutPut::outJsonToError(
            'System Error',
            $code,
            $throwable->getThrowData(),
            $throwable->getThrowTrace()
        );
        return $response->withStatus(200)
                        ->withBody(new SwooleStream($output));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ServerException;
    }
}
