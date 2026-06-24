<?php

declare(strict_types=1);

/**
 * config/autoload/dependencies.php 示例片段。
 *
 * 复制到业务项目后按实际类名调整右侧绑定。本文件在 examples 目录下不会被 Hyperf 自动加载。
 */
return [
    // 这两个绑定需要留在业务项目 config/autoload/dependencies.php,用于稳定覆盖 Hyperf 默认绑定。
    \Hyperf\HttpMessage\Server\RequestParserInterface::class
        => \Dleno\CommonCore\Core\Request\RequestParser::class,
    \Hyperf\HttpServer\Contract\RequestInterface::class
        => \Dleno\CommonCore\Core\Request\Request::class,

    // HTTP 模块前置中间件绑定:复制 Middleware/AppModuleBeforeMiddleware 到业务 app 后替换右侧类名。
    \Dleno\CommonCore\Middleware\Http\AbstractModuleBeforeMiddleware::class
        => \Dleno\CommonCore\Examples\Middleware\AppModuleBeforeMiddleware::class,

    // WS 业务策略/钩子绑定:右侧换成业务 app 下复制出的实现。
    \Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface::class
        => \Dleno\CommonCore\Examples\WebSocket\Bind\DefaultWsBindStrategy::class,
    \Dleno\CommonCore\Websocket\Contract\WsHookInterface::class
        => \Dleno\CommonCore\Examples\WebSocket\Hook\AppWsHook::class,
];
