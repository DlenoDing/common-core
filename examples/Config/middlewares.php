<?php

declare(strict_types=1);

/**
 * config/autoload/middlewares.php 示例片段。
 *
 * common-core 会按 ENABLE_HTTP / ENABLE_WS 自动注入基础中间件。
 * 业务自定义中间件只需在这里追加;如要替换基础中间件,先用 env 关闭自动注入再自行列出。
 */
return [
    // 'http' => [
    //     \App\Middleware\YourHttpMiddleware::class,
    // ],
    // 'ws' => [
    //     \App\Middleware\YourWsMiddleware::class,
    // ],
];
