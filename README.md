# dleno/common-core

`dleno/common-core` 是 Dleno Hyperf 3.1 项目的公共核心包，负责沉淀 HTTP/RPC/Model/AMQP/AsyncQueue/WebSocket 等基础能力，以及项目通用工具、全局函数、异常输出、日志输出、分布式锁和示例模板。

它的定位是“框架基座 + 业务可接入的公共组件”，不是一个完整业务应用。业务项目只需要安装包、补齐少量 DI 绑定和业务策略，就可以复用统一的请求解析、输出格式、模型能力、队列封装、WebSocket 连接管理和在线检查能力。

## 环境要求

- PHP `>= 8.1`
- Swoole `>= 5.0`
- Hyperf `~3.1`
- ext-curl
- WebSocket 功能依赖 Redis `7.4+` 的 `HEXPIRE` 能力

启用 WebSocket 服务时，启动前会校验：

- `server.mode` 必须是 `SWOOLE_BASE`，否则直接终止启动。
- Redis 可达但不支持 `HEXPIRE` 时直接终止启动。
- Redis 暂时不可达时只输出 WARN 放行，避免启动编排中的短暂网络抖动误杀服务；运行时仍要求 Redis 7.4+ 可用。

## 安装

```bash
composer require dleno/common-core:^3.1
```

安装后包内 `ConfigProvider` 会自动生效：

- 扫描 `src/` 下的注解。
- 自动加载 `src/common/Functions.php` 全局函数。
- 通过 `dleno/hyperf-env-multi` 显式加载环境文件：先加载 `.env`，再按 `APP_ENV` 加载 `.env.<APP_ENV>`，环境文件中的同名变量以后者为准。
- 注入部分基础 dependencies。
- 按 `ENABLE_HTTP` / `ENABLE_WS` 自动注入 HTTP / WS 基础中间件。
- 提供 WebSocket 配置发布模板，可通过 `vendor:publish` 写入 `config/autoload/websocket.php`。

发布配置：

```bash
php bin/hyperf.php vendor:publish dleno/common-core
```

## 接入清单

### HTTP 请求对象绑定

Hyperf 自身也会绑定 `RequestParserInterface` 和 `RequestInterface`，因此这两个绑定需要放在业务项目 `config/autoload/dependencies.php`，确保优先级稳定：

```php
return [
    Hyperf\HttpMessage\Server\RequestParserInterface::class
        => Dleno\CommonCore\Core\Request\RequestParser::class,
    Hyperf\HttpServer\Contract\RequestInterface::class
        => Dleno\CommonCore\Core\Request\Request::class,
];
```

`CoreMiddleware` 和 `DispatcherFactory` 已由 common-core 的 `ConfigProvider` 注入，通常不需要业务项目重复绑定。

### 异常处理器配置

异常处理链顺序敏感，`DefaultExceptionHandler` 会兜底并终止后续传播。因此 common-core 不通过 `ConfigProvider` 自动注入 `exceptions.handler`，而是提供 `ExceptionHandlerConfig` 让业务项目在 `config/autoload/exceptions.php` 中显式生成默认链，并保留业务 handler 的插入位置：

```php
use Dleno\CommonCore\Exception\ExceptionHandlerConfig;

return [
    'handler' => ExceptionHandlerConfig::defaultHandlers(
        httpCommonHandlers: [
            App\Exception\Handler\BusinessCommonExceptionHandler::class,
        ],
        wsCommonHandlers: [
            App\WebSocket\Exception\Handler\BusinessCommonWsExceptionHandler::class,
        ],
        httpBeforeDefault: [
            App\Exception\Handler\BusinessOutputExceptionHandler::class,
        ],
        wsBeforeDefault: [
            App\WebSocket\Exception\Handler\BusinessWsOutputExceptionHandler::class,
        ],
    ),
];
```

`httpCommonHandlers` / `wsCommonHandlers` 会插入到 common-core 的 `CommonExceptionHandler` 之后，用于回滚、审计、上下文清理等公共前置处理，不应调用 `stopPropagation()`；`httpBeforeDefault` / `wsBeforeDefault` 会插入到对应协议的 `DefaultExceptionHandler` 之前，用于业务输出类 handler。未启用 `ENABLE_HTTP` / `ENABLE_WS` 时不会生成对应 server 的异常链。

### WebSocket 业务策略绑定

WebSocket 的连接维度属于业务决策，common-core 不提供默认绑定策略。业务项目必须在 `dependencies.php` 中绑定 `WsBindStrategyInterface`：

```php
return [
    Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface::class
        => App\WebSocket\Bind\DefaultWsBindStrategy::class,
];
```

`WsHookInterface` 已有 no-op 默认实现，业务不需要身份解析、握手校验或生命周期钩子时可以不覆盖；需要接入登录态、握手鉴权、open/close/heartbeat/message/send 等业务逻辑时再绑定：

```php
return [
    Dleno\CommonCore\Websocket\Contract\WsHookInterface::class
        => App\WebSocket\Hook\AppWsHook::class,
];
```

参考实现见：

- `examples/WebSocket/Bind/DefaultWsBindStrategy.php`
- `examples/WebSocket/Bind/MultiDimWsBindStrategy.php`
- `examples/WebSocket/Hook/AppWsHook.php`

## 核心能力

### HTTP 基座

相关目录：

- `src/Base/BaseCoreController.php`
- `src/Middleware/Http`
- `src/Core/Request`
- `src/Core/Route`
- `src/Annotation`
- `src/Aspect`

主要能力：

- Controller 自动注入对应 Service。
- `successData()` 统一返回结构。
- `checkParams()` 参数校验。
- `LockCheckTrait` 提供分布式锁辅助。
- `OutputHtml`、`OutputNoLog`、`OutputNotFormat` 等注解控制输出格式、日志和字段格式化。
- HTTP 输出切面自动记录响应日志，并在 `API_DATA_CRYPT` 开启时加密响应体。
- HTTP 请求初始化、语言/时区/traceId 等上下文处理。
- 路由分发增强和请求对象替换。
- AutoController 请求方式控制：方法级 `#[AllowMethods]` 优先，其次类级 `AutoController(defaultMethods)`、`config('app.default_allow_methods')`、默认 `['POST', 'GET']`；包含 `GET` 时自动补 `HEAD`，`OPTIONS` 预检由 `InitMiddleware` 统一处理。
- 模块前置中间件提供签名校验、请求解密和登录/防重放钩子。抽象基类 `AbstractModuleBeforeMiddleware` 封装流程，由 `ConfigProvider` 在 `InitMiddleware` 之后、复用同一 `HTTP_INIT_MIDDLEWARE_ENABLE` 开关注册其类名，并默认绑定到包内具体实现 `DefaultModuleBeforeMiddleware`（`checkAuth` 为 no-op）。作为全局中间件运行于「路由分发之后、控制器之前」（Hyperf 在中间件管线前已完成分发并写入 `Dispatched`）：仅对已命中路由（`FOUND`）经 `Server::getRouteMca()` 取权威路由做签名/解密/鉴权；非 `FOUND`（`NOT_FOUND` / `METHOD_NOT_ALLOWED`）直接放行，由管线末端 `CoreMiddleware` 返 404 / 405。业务侧继承 `AbstractModuleBeforeMiddleware` 覆写钩子，并在 `dependencies.php` 把抽象基类绑定到自己的子类即接管。`checkAuth($request)` 默认 no-op，仅在「未命中 TOKEN 白名单且非（非正式环境 debug）」时由基类调用，`$request` 为解密后的请求；`checkReplay(): bool` 默认返回 `true` 放行，业务覆盖返回 `false` 时由框架统一按签名错误处理，典型实现是在签名通过后用 `Client-Sign` 做 Redis `SET NX` 带 TTL 占位。

示例：

- `examples/Http`
- `examples/Aspect`
- `examples/Config`

### 异常与输出

相关目录：

- `src/Exception`
- `src/Tools/OutPut.php`
- `src/Tools/Output`
- `src/Tools/Logger.php`

主要能力：

- HTTP/RPC 常用异常处理器。
- `ExceptionHandlerConfig` 生成 HTTP / WS 默认异常链，并支持业务公共前置 handler、兜底前输出 handler 插入到固定位置。
- 统一 JSON 输出。
- API/RPC/Error 输出日志封装。
- HTTP/WS Controller 响应日志切面。
- 按渠道输出 stdout、system、api、sql、business 日志。

### Model 与数据库

相关目录：

- `src/Model`
- `src/Db`

主要能力：

- `BaseModel` 统一模型基类。
- 自定义 `EloquentBuilder`，支持分页、分组统计、批量插入更新、全文检索等项目增强方法。
- 支持按年/月/日/周/固定数量分表。
- `findById()`、`updateById()`、`deleteById()` 按主键路由到对应分表。
- `withTable()` 支持指定分表后缀查询。
- 模型生成时保持 decimal 精度处理。

示例：

- `examples/Model/BaseModel.php`
- `examples/Model/Test.php`
- `examples/Model/TestSplit.php`

### AMQP

相关目录：

- `src/Base/Amqp`
- `src/Tools/Amqp`

主要能力：

- `BaseProducer`、`BaseConsumer` 封装 Hyperf AMQP。
- 支持延迟交换机。
- 支持死信交换机、死信路由、消息 TTL、队列 TTL。
- 支持生产者确认。
- 支持静态队列和按服务器动态 routingKey / queue。

示例：

- `examples/Amqp`
- `examples/Amqp/Dynamic`

### AsyncQueue

相关目录：

- `src/Base/AsyncQueue`
- `src/Tools/AsyncQueue`

主要能力：

- `BaseJob` 统一队列 Job 基类。
- `BaseQueueConsumer` 统一消费进程基类。
- `BaseDriverFactory` 支持动态队列驱动配置。
- `AsyncQueue::push()` 会按 Job 的 queue 自动选择对应 driver。
- 支持静态队列和按服务器动态队列。

示例：

- `examples/AsyncQueue`
- `examples/AsyncQueue/Dynamic`

### WebSocket

相关目录：

- `src/Websocket`
- `publish/websocket.php`

主要能力：

- WebSocket 握手鉴权中间件。
- WS Controller 注解和消息路由。
- 生命周期 Hook：握手前/中/后、open、close、heartbeat、message、send 等。
- 连接绑定策略：`bindDimensions()`、`addressableDimensions()`、`onlineCheckDimensions()`、`uniqueDimensions()`。
- 按维度推送：单 fd、批量 fd、广播、按业务维度推送。
- 连接唯一性：支持同账号单连接、同账号同设备单连接等踢旧策略。
- 实时在线检查：`checkRealtimeOnlineByDim()`，适合小批量、强实时、unique 维度。
- 心跳在线检查：`checkHeartbeatOnlineByDim()`，基于 Redis 7.4+ `HEXPIRE` 和 presence bucket 索引，适合大批量心跳级判断。
- 全量心跳在线快照：`checkHeartbeatOnlineAllByDim()`，适合后台/统计类低频场景。
- per-server 实时消息队列和可选独立控制队列，避免在线核验/断连阻塞真实消息下发。

维度配置建议：

- `addressableDimensions()` 用于“能不能按这个维度推送/寻址”。
- `onlineCheckDimensions()` 用于“能不能按这个维度做心跳在线检查”。
- `uniqueDimensions()` 用于“这个维度是否单连接/踢旧”。
- 低基数维度如 `device_type=ios/android/h5` 可以用于推送寻址，但不应放入 `onlineCheckDimensions()`，否则单个 value 可能挂大量连接，在线检查会变重。
- 高基数且单 value 连接数可控的维度，如 `account_id`，更适合做在线检查。

WebSocket 配置见 `config/autoload/websocket.php`，模板来源为 `publish/websocket.php`。重点配置：

- `key_prefix`: WS Redis key 前缀。
- `local_enable`: local 环境是否启用 WS 常驻进程。
- `server_set_cache_ms`: 在线服务器集合短缓存。
- `presence_bucket_num`: 心跳 presence 索引 bucket 数。
- `realtime_online.max` / `realtime_online.timeout`: 实时在线核验批量上限与等待超时。
- `queue`: 实时消息队列消费进程与并发配置。
- `dedicated_queue`: 独立控制队列配置。

示例：

- `examples/WebSocket`

### JsonRpc / MQ RPC

相关目录：

- `src/JsonRpc`

主要能力：

- JSON-RPC 客户端代理。
- MQ 异步 RPC 封装。
- MQ RPC 失败重试和异常链保留。

示例：

- `examples/Tools/UsageExamples.php`

### 工具类

相关目录：

- `src/Tools`
- `src/Traits`
- `src/common/Functions.php`

常用能力：

- `DcsLock`: Redis 分布式锁，支持等待、自动续期、Lua 解锁。
- `HttpClient`: 协程内走 Swoole Coroutine Client，非协程内走 curl。
- `OpenSslCrypt`: AES/DES 对称加解密。
- `OpenSslRsa` / `OpenSslRsa2`: RSA 分块加解密。两者协议不同——`OpenSslRsa` 密文为 hex（偏长），`OpenSslRsa2` 密文为 base64（约短一半）。接口加密的 `Client-Key`（AES 密钥）解密走 `OpenSslRsa2`，客户端须使用同款算法加密。
- `CheckVal` / `CheckParams`: 常用格式校验和参数校验。
- `Client` / `Server`: 客户端信息、IP、设备、语言、路由、traceId 等辅助。
- `ArrayTool`、`Strings`、`TimeTool`、`Distribution` 等工具。
- `ObjectAttribute`: 对象属性填充/导出辅助。

示例：

- `examples/Tools/UsageExamples.php`

## examples 目录

`examples/` 是使用示例集合，不会随包安装自动执行。

安全边界：

- `composer.json` 只 autoload `src/`，不加载 `examples/`。
- `ConfigProvider` 只扫描 `src/`，不扫描 `examples/`。
- 示例命名空间为 `Dleno\CommonCore\Examples\...`，不会占用业务 `App\...`。
- AMQP Consumer 示例的 `isEnable()` 保留 `AMQP_ENABLE` 前置门禁；Crontab 示例保留 `ENABLE_CRONTAB` 前置门禁；随后都会拦截 `local` 环境并默认 `return false`，避免误扫后真实执行。
- HTTP Controller、WS Controller、Aspect 示例刻意不直接声明 `#[AutoController]`、`#[WsController]`、`#[Aspect]`，复制到业务项目后再按注释启用。

使用方式：

1. 复制需要的示例到业务项目 `app/` 下对应目录。
2. 修改 namespace，例如改成 `App\WebSocket\Bind`。
3. 根据业务把 `isEnable()` 改成自己的启用条件；复制后建议保留 `AMQP_ENABLE` / `ENABLE_CRONTAB` 等功能开关和 `local` 环境强制关闭判断。
4. 不要直接把 `vendor/dleno/common-core/examples` 加入业务注解扫描路径。

## 常用环境变量

基础开关：

- `APP_ENV`: 当前运行环境；存在 `.env.<APP_ENV>` 时会覆盖 `.env` 中的同名变量。
- `ENABLE_HTTP`: 是否启用 HTTP server 相关基础中间件。
- `ENABLE_WS`: 是否启用 WebSocket 相关进程和中间件。
- `HTTP_INIT_MIDDLEWARE_ENABLE`: 是否自动注入 HTTP 初始化中间件，默认开启。
- `WS_AUTH_MIDDLEWARE_ENABLE`: 是否自动注入 WS 鉴权中间件，默认开启。

WebSocket：

- `WS_KEY_PREFIX`
- `WS_LOCAL_ENABLE`
- `WS_SERVER_SET_CACHE_MS`
- `WS_PRESENCE_BUCKET_NUM`
- `WS_REALTIME_ONLINE_MAX`
- `WS_REALTIME_ONLINE_TIMEOUT`
- `WS_CONSUMER_PROCESSES`
- `WS_CONSUMER_LIMIT`
- `WS_CONSUMER_MAX_MESSAGES`
- `WS_DEDICATED_QUEUE_ENABLE`
- `WS_DEDICATED_PROCESSES`
- `WS_DEDICATED_LIMIT`
- `WS_DEDICATED_MAX_MESSAGES`

## 测试与校验

本包当前 `composer test` 执行 PHP 语法检查，覆盖 `src`、`publish`、`examples`：

```bash
composer test
```

Composer 文件校验：

```bash
composer validate --strict
```

## 目录结构

```text
src/                  核心源码
publish/              可发布配置模板
examples/             使用示例,不会自动加载或执行
.github/workflows/    Release 等自动化工作流
```

## 设计原则

- 核心协议和运行时约束放在 common-core 内统一维护。
- 业务差异通过策略、Hook、配置和示例复制接入。
- WebSocket 低层队列、注册、presence、在线检查逻辑不建议业务继承改写。
- 启动期能确定的错误尽量 fail-fast，但 Redis 暂时不可达只告警，避免误杀编排过程中的短暂抖动。
- 示例代码只作为模板，不作为运行时功能的一部分。
