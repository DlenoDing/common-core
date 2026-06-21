<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception\Handler\Http;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Dleno\CommonCore\Annotation\ExceptionHandlerLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class ServerExceptionHandler
 * @package Dleno\CommonCore\Exception\Handler\Http
 */
class ServerExceptionHandler extends ExceptionHandler
{
    use HttpErrorResponder;

    /**
     * @param ServerException $throwable
     * @param ResponseInterface $response
     * @return mixed
     */
    #[ExceptionHandlerLog]
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $code = $throwable->getCode();
        $code = $code ?: RcodeConf::ERRNO_NORMAL;

        //数据返回
        return $this->respond($response, 'System Error', (int) $code, 200, $throwable->getThrowData(), $throwable->getThrowTrace());
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ServerException;
    }
}
