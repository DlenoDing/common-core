<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Psr\Http\Message\ServerRequestInterface;

class CoreMiddleware extends \Hyperf\HttpServer\CoreMiddleware
{
    /**
     * Handle the response when cannot found any routes.
     */
    protected function handleNotFound(ServerRequestInterface $request): mixed
    {
        // 重写路由找不到的处理逻辑
        //return $this->response()->withStatus(404);
        throw new HttpException('Not Found', RcodeConf::ERROR_NOTFOUND);
    }

    /**
     * Handle the response when the routes found but doesn't match any available methods.
     */
    protected function handleMethodNotAllowed(array $methods, ServerRequestInterface $request): mixed
    {
        //throw new MethodNotAllowedHttpException('Allow: ' . implode(', ', $methods));
        // 重写 HTTP 方法不允许的处理逻辑
        throw new HttpException('Method Not Allowed', RcodeConf::ERROR_METHOD_NOT_ALLOWED);
    }
}
