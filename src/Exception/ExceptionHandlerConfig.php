<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception;

use Dleno\CommonCore\Exception\Handler\CommonExceptionHandler;
use Dleno\CommonCore\Exception\Handler\Http\AppExceptionHandler;
use Dleno\CommonCore\Exception\Handler\Http\DefaultExceptionHandler;
use Dleno\CommonCore\Exception\Handler\Http\HttpExceptionHandler;
use Dleno\CommonCore\Exception\Handler\Http\RpcClientRequestExceptionHandler;
use Dleno\CommonCore\Exception\Handler\Http\ServerExceptionHandler;
use Dleno\CommonCore\Exception\Handler\Http\ValidationExceptionHandler;
use Dleno\CommonCore\Websocket\Exception\Handler\AppExceptionHandler as WsAppExceptionHandler;
use Dleno\CommonCore\Websocket\Exception\Handler\DefaultExceptionHandler as WsDefaultExceptionHandler;
use Dleno\CommonCore\Websocket\Exception\Handler\HttpExceptionHandler as WsHttpExceptionHandler;
use Dleno\CommonCore\Websocket\Exception\Handler\RpcClientRequestExceptionHandler as WsRpcClientRequestExceptionHandler;
use Dleno\CommonCore\Websocket\Exception\Handler\ServerExceptionHandler as WsServerExceptionHandler;
use Dleno\CommonCore\Websocket\Exception\Handler\ValidationExceptionHandler as WsValidationExceptionHandler;
use InvalidArgumentException;

use function Hyperf\Support\env;

class ExceptionHandlerConfig
{
    /**
     * 根据启用的 server 生成默认异常处理链。
     *
     * 公共前置 handler 不应中断传播；输出类 handler 应插入到 DefaultExceptionHandler 之前。
     *
     * @param array<class-string> $httpCommonHandlers
     * @param array<class-string> $wsCommonHandlers
     * @param array<class-string> $httpBeforeDefault
     * @param array<class-string> $wsBeforeDefault
     */
    public static function defaultHandlers(
        array $httpCommonHandlers = [],
        array $wsCommonHandlers = [],
        array $httpBeforeDefault = [],
        array $wsBeforeDefault = [],
    ): array {
        $handlers = [];

        if (env('ENABLE_HTTP', false)) {
            $handlers['http'] = self::httpHandlers($httpCommonHandlers, $httpBeforeDefault);
        }

        if (env('ENABLE_WS', false)) {
            $handlers['ws'] = self::wsHandlers($wsCommonHandlers, $wsBeforeDefault);
        }

        return $handlers;
    }

    /**
     * HTTP 异常处理链。
     *
     * 顺序约定：
     *  - CommonExceptionHandler 先执行公共回滚且不中断。
     *  - 公共前置 handler 插入在 CommonExceptionHandler 之后，且不应中断传播。
     *  - 输出类 handler 插入在 ServerExceptionHandler 与 DefaultExceptionHandler 之间。
     *  - DefaultExceptionHandler 必须最后兜底。
     *
     * @param array<class-string> $commonHandlers
     * @param array<class-string> $beforeDefault
     */
    public static function httpHandlers(array $commonHandlers = [], array $beforeDefault = []): array
    {
        return self::mergeHandlers([
            CommonExceptionHandler::class,
        ], $commonHandlers, [
            HttpExceptionHandler::class,
            ValidationExceptionHandler::class,
            RpcClientRequestExceptionHandler::class,
            AppExceptionHandler::class,
            ServerExceptionHandler::class,
        ], $beforeDefault, [
            DefaultExceptionHandler::class,
        ]);
    }

    /**
     * WebSocket 异常处理链。
     *
     * @param array<class-string> $commonHandlers
     * @param array<class-string> $beforeDefault
     */
    public static function wsHandlers(array $commonHandlers = [], array $beforeDefault = []): array
    {
        return self::mergeHandlers([
            CommonExceptionHandler::class,
        ], $commonHandlers, [
            WsHttpExceptionHandler::class,
            WsValidationExceptionHandler::class,
            WsRpcClientRequestExceptionHandler::class,
            WsAppExceptionHandler::class,
            WsServerExceptionHandler::class,
        ], $beforeDefault, [
            WsDefaultExceptionHandler::class,
        ]);
    }

    private static function mergeHandlers(array ...$groups): array
    {
        $handlers = [];

        foreach ($groups as $group) {
            foreach ($group as $handler) {
                if (! is_string($handler) || $handler === '') {
                    throw new InvalidArgumentException('Exception handler must be a non-empty class string.');
                }

                if (isset($handlers[$handler])) {
                    continue;
                }

                $handlers[$handler] = $handler;
            }
        }

        return array_values($handlers);
    }
}
