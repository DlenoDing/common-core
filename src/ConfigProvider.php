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

use Dleno\HyperfEnvMulti\EnvLoader;
use Hyperf\AsyncQueue\Listener\ReloadChannelListener;
use Hyperf\Command\Listener\FailToHandleListener;
use Hyperf\JsonRpc\JsonRpcPoolTransporter;
use Hyperf\JsonRpc\JsonRpcTransporter;
use Hyperf\Crontab\Process\CrontabDispatcherProcess;
use Hyperf\ExceptionHandler\Listener\ErrorExceptionHandler;
use Hyperf\Serializer\Serializer;
use Hyperf\Serializer\SerializerFactory;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\HttpServer\CoreMiddleware;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\Database\Commands\Ast\ModelUpdateVisitor as AstModelUpdateVisitor;
use Dleno\CommonCore\Model\ModelUpdateVisitor;
use Dleno\CommonCore\Signal\ProcessStopHandler;
use Dleno\CommonCore\Websocket\Contract\WsHookInterface;
use Dleno\CommonCore\Websocket\Hook\AbstractWsHook;
use Dleno\CommonCore\Middleware\Http\AbstractModuleBeforeMiddleware;
use Dleno\CommonCore\Middleware\Http\DefaultModuleBeforeMiddleware;
use Dleno\CommonCore\Middleware\Http\InitMiddleware;
use Dleno\CommonCore\Websocket\Server\WebSocketAuthMiddleware;
use Dleno\CommonCore\Middleware\Http\CoreMiddleware as HttpCoreMiddleware;
use Dleno\CommonCore\Core\Route\RouterDispatcherFactory as RouterDispatcherFactory;

use function Hyperf\Support\env;

class ConfigProvider
{
    public function __invoke(): array
    {
        EnvLoader::load(BASE_PATH);

        return [
            //基础监听器自动注册；业务项目如需自定义 listener，只追加自己的 listener，避免重复注册这里的基础项。
            'listeners'    => $this->baseListeners(),
            //基础进程自动注册；业务项目如需自定义 process，只追加自己的 process，避免重复注册这里的基础项。
            'processes'    => $this->autoProcesses(),
            //基础退出信号处理自动注册；timeout 不在包内定义，避免业务侧设置标量时被 array_merge_recursive 合成数组。
            'signal'       => $this->baseSignal(),
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
                CoreMiddleware::class       => HttpCoreMiddleware::class,
                DispatcherFactory::class    => RouterDispatcherFactory::class,

                //WS 默认绑定：钩子默认 no-op（业务不覆盖即零成本；身份解析现走 WsHook::onHandshake）。
                //WsBindStrategyInterface 无包内默认，业务必须在 app dependencies.php 绑定
                //（绑定策略默认实现已下放业务端：App\WebSocket\Bind\DefaultWsBindStrategy）。
                WsHookInterface::class => AbstractWsHook::class,

                //模块前置中间件默认实现：autoMiddlewares 注册的是抽象基类 AbstractModuleBeforeMiddleware，
                //此处给出包内默认具体实现（checkAuth no-op）。业务在 app dependencies.php 把抽象类绑到自己的子类即接管。
                AbstractModuleBeforeMiddleware::class => DefaultModuleBeforeMiddleware::class,
            ],
            //安装时自动发布的配置模板(vendor:publish);destination 已存在则不覆盖,业务可自由修改。
            'publish'      => [
                [
                    'id'          => 'websocket',
                    'description' => 'WS 业务可控配置(前缀 / 队列 / 独立控制队列 / presence / 在线检查 等调优旋钮)。',
                    'source'      => __DIR__ . '/../publish/websocket.php',
                    'destination' => BASE_PATH . '/config/autoload/websocket.php',
                ],
            ],
            'annotations'  => [
                'scan' => [
                    'paths'              => [
                        __DIR__,
                    ],
                    'class_map'          => [
                        // 需要映射的类名 => 类所在的文件地址
                        Coroutine::class               => __DIR__ . '/class_map/Hyperf/Coroutine/Coroutine.php',
                        //Builder 不再整类 fork:自定义方法已下沉到 Dleno\CommonCore\Model\EloquentBuilder(继承框架 Builder),
                        //由 BaseModel::newModelBuilder() 注入;框架 Builder 修复/安全补丁自动继承。
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
     * 基础监听器注册。
     *
     * 这些监听器属于框架运行基座：
     *  - PHP error 转 ErrorException
     *  - 命令异常输出
     *  - AsyncQueue timeout channel 自动 reload
     */
    private function baseListeners(): array
    {
        return [
            ErrorExceptionHandler::class,
            FailToHandleListener::class,
            ReloadChannelListener::class,
        ];
    }

    /**
     * 基础进程注册。
     *
     * Crontab 调度进程只由 ENABLE_CRONTAB 控制；业务进程仍由业务项目自行定义。
     */
    private function autoProcesses(): array
    {
        $processes = [];
        if (env('ENABLE_CRONTAB', false)) {
            $processes[] = CrontabDispatcherProcess::class;
        }
        return $processes;
    }

    /**
     * 基础退出信号处理。
     *
     * 只注册 handler，不定义 signal.timeout；业务项目可在 signal.php 追加 handler 或自行设置 timeout。
     */
    private function baseSignal(): array
    {
        return [
            'handlers' => [
                ProcessStopHandler::class,
            ],
        ];
    }

    /**
     * 基础中间件自动注入。
     * 按"对应 server 是否启用"自动给该 server 注入包内基座中间件：
     *  - http server(ENABLE_HTTP) → InitMiddleware
     *  - ws   server(ENABLE_WS)   → WebSocketAuthMiddleware（握手鉴权）
     * 各有独立 env 开关、默认开；特殊需求时置 false 即不再自动注入（业务自行在 middlewares.php 接管）。
     * 与 app config/autoload/middlewares.php 为追加合并（包内的排在前、业务的追加在后）——
     * **Hyperf 对此普通中间件列表不自动去重**：要自行接管请用上述 env 关掉基座注入，切勿再手动追加同名中间件（否则会执行两次）。
     */
    private function autoMiddlewares(): array
    {
        $middlewares = [];
        //HTTP 初始化中间件（env HTTP_INIT_MIDDLEWARE_ENABLE，默认开）；
        //紧随其后注入模块前置中间件（签名校验/数据解密/登录校验），复用同一开关、排在 InitMiddleware 之后
        //（依赖其写入的时区/解析体/RPC 上下文等）。默认走 AbstractModuleBeforeMiddleware（no-op 鉴权、安全放行）；
        //业务在 app dependencies.php 把 AbstractModuleBeforeMiddleware 绑到自己的子类即覆盖。
        if (env('ENABLE_HTTP', false) && env('HTTP_INIT_MIDDLEWARE_ENABLE', true)) {
            $middlewares['http'][] = InitMiddleware::class;
            $middlewares['http'][] = AbstractModuleBeforeMiddleware::class;
        }
        //WS 握手鉴权中间件（env WS_AUTH_MIDDLEWARE_ENABLE，默认开）
        if (env('ENABLE_WS', false) && env('WS_AUTH_MIDDLEWARE_ENABLE', true)) {
            $middlewares['ws'][] = WebSocketAuthMiddleware::class;
        }
        return $middlewares;
    }
}
