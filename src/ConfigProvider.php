<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Dleno\CommonCore;

use Hyperf\JsonRpc\JsonRpcPoolTransporter;
use Hyperf\JsonRpc\JsonRpcTransporter;
use Hyperf\Serializer\Serializer;
use Hyperf\Serializer\SerializerFactory;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Database\Commands\Ast\ModelUpdateVisitor as AstModelUpdateVisitor;
use Hyperf\Database\Model\Builder;
use Dleno\CommonCore\Model\ModelUpdateVisitor;
use Dleno\CommonCore\Websocket\Contract\WsHookInterface;
use Dleno\CommonCore\Websocket\Hook\AbstractWsHook;
use Dleno\CommonCore\Middleware\Http\InitMiddleware;
use Dleno\CommonCore\Websocket\Server\WebSocketAuthMiddleware;

use function Hyperf\Support\env;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            //基础中间件自动注入（按启用的 server 注入对应基座中间件，与 app middlewares.php 追加合并）。
            'middlewares'  => $this->autoMiddlewares(),
            'dependencies' => [
                //db连接池恒频组件(默认低频组件)[释放连接池中多余的连接]
                //\Hyperf\DbConnection\Frequency::class => \Hyperf\Pool\ConstantFrequency::class,

                //decimal 精度(默认会转float)
                AstModelUpdateVisitor::class => ModelUpdateVisitor::class,

                //JsonRpc连接池-对端jsonrpc时才有用，jsonrpc-http使用的HttpTransporter
                JsonRpcTransporter::class    => JsonRpcPoolTransporter::class,

                //JsonRpc返回 PHP 对象
                NormalizerInterface::class   => new SerializerFactory(Serializer::class),

                //HTTP 路由核心：Hyperf 未在自身 ConfigProvider 绑定这两个，故包内自注入即可生效（业务可在 app 覆盖）。
                //注：RequestParserInterface / RequestInterface 因 Hyperf 自身 ConfigProvider 也绑、包内覆盖不住，
                //   必须留在 app config/autoload/dependencies.php（唯一能稳定压过所有 ConfigProvider 的层）。
                \Hyperf\HttpServer\CoreMiddleware::class
                    => \Dleno\CommonCore\Middleware\Http\CoreMiddleware::class,
                \Hyperf\HttpServer\Router\DispatcherFactory::class
                    => \Dleno\CommonCore\Core\Route\RouterDispatcherFactory::class,

                //WS 默认绑定：钩子默认 no-op（业务不覆盖即零成本；身份解析现走 WsHook::onHandshake）。
                //WsBindStrategyInterface 无包内默认，业务必须在 app dependencies.php 绑定
                //（绑定策略默认实现已下放业务端：App\WebSocket\Bind\DefaultWsBindStrategy）。
                WsHookInterface::class => AbstractWsHook::class,
            ],
            'annotations'  => [
                'scan' => [
                    'paths'              => [
                        __DIR__,
                    ],
                    'class_map'          => [
                        // 需要映射的类名 => 类所在的文件地址
                        Coroutine::class               => __DIR__ . '/class_map/Hyperf/Coroutine/Coroutine.php',
                        Builder::class                 => __DIR__ . '/class_map/Hyperf/Database/Model/Builder.php',
                    ],
                    // ignore_annotations 数组内的注解都会被注解扫描器忽略
                    'ignore_annotations' => [
                        'DateTime',
                        'desc',
                    ],
                ],
            ],
        ];
    }

    /**
     * 基础中间件自动注入。
     * 按"对应 server 是否启用"自动给该 server 注入包内基座中间件：
     *  - http server(ENABLE_HTTP) → InitMiddleware
     *  - ws   server(ENABLE_WS)   → WebSocketAuthMiddleware（握手鉴权）
     * 各有独立 env 开关、默认开；特殊需求时置 false 即不再自动注入（业务自行在 middlewares.php 接管）。
     * 与 app config/autoload/middlewares.php 为追加合并（包内的排在前、业务的追加在后、按类名去重）。
     */
    private function autoMiddlewares(): array
    {
        $middlewares = [];
        //HTTP 初始化中间件（env HTTP_INIT_MIDDLEWARE_ENABLE，默认开）
        if (env('ENABLE_HTTP', false) && env('HTTP_INIT_MIDDLEWARE_ENABLE', true)) {
            $middlewares['http'][] = InitMiddleware::class;
        }
        //WS 握手鉴权中间件（env WS_AUTH_MIDDLEWARE_ENABLE，默认开）
        if (env('ENABLE_WS', false) && env('WS_AUTH_MIDDLEWARE_ENABLE', true)) {
            $middlewares['ws'][] = WebSocketAuthMiddleware::class;
        }
        return $middlewares;
    }
}
