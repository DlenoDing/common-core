<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Hyperf\Codec\Json;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hyperf\HttpMessage\Server\ResponsePlusProxy;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

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

    /**
     * Transfer the non-standard response content to a standard response object.
     *
     * @param null|array|Arrayable|Jsonable|ResponseInterface|string $response
     */
    protected function transferToResponse($response, ServerRequestInterface $request): ResponsePlusInterface
    {
        if (is_string($response)) {
            $responseObj = $this->response();
            if (!$responseObj->hasHeader('content-type')) {
                $responseObj->addHeader('content-type', 'text/plain');
            }
            return $responseObj->setBody(new SwooleStream($response));
        }

        if ($response instanceof ResponseInterface) {
            return new ResponsePlusProxy($response);
        }

        if (is_array($response) || $response instanceof Arrayable) {
            return $this->response()
                        ->addHeader('content-type', 'application/json')
                        ->setBody(new SwooleStream(Json::encode($response)));
        }

        if ($response instanceof Jsonable) {
            return $this->response()
                        ->addHeader('content-type', 'application/json')
                        ->setBody(new SwooleStream((string)$response));
        }

        if ($this->response()
                 ->hasHeader('content-type')) {
            return $this->response()
                        ->setBody(new SwooleStream((string)$response));
        }

        return $this->response()
                    ->addHeader('content-type', 'text/plain')
                    ->setBody(new SwooleStream((string)$response));
    }

    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response     = parent::process($request, $handler);
        $serverHeader = $response->getHeader('Server');
        foreach ($serverHeader as $k => $v) {
            if (strpos(strtolower($v), 'hyperf') !== false) {
                unset($serverHeader[$k]);
            }
        }
        return $response->withoutHeader('Server')
                        ->withHeader('Server', $serverHeader);
    }
}
