<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Hyperf\Utils\Contracts\Arrayable;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CoreMiddleware extends \Hyperf\HttpServer\CoreMiddleware
{
    /**
     * Handle the response when cannot found any routes.
     *
     * @return array|Arrayable|mixed|ResponseInterface|string
     */
    protected function handleNotFound(ServerRequestInterface $request)
    {
        // 重写路由找不到的处理逻辑
        //return $this->response()->withStatus(404);
        throw new HttpException('Not Found', RcodeConf::ERROR_NOTFOUND);
    }

    /**
     * Handle the response when the routes found but doesn't match any available methods.
     *
     * @return array|Arrayable|mixed|ResponseInterface|string
     */
    protected function handleMethodNotAllowed(array $methods, ServerRequestInterface $request)
    {
        // 重写 HTTP 方法不允许的处理逻辑
        //return $this->response()->withStatus(405);
        throw new HttpException('Method Not Allowed', RcodeConf::ERROR_METHOD_NOT_ALLOWED);
    }
}
