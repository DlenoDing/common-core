# common-core examples

`examples/` 目录仅放示例代码,不会随 `dleno/common-core` 安装后自动执行。

安全边界:

- `composer.json` 只 autoload `src/`,不加载 `examples/`。
- `ConfigProvider` 只把 `src/` 加入注解扫描路径,不扫描 `examples/`。
- 示例类使用 `Dleno\CommonCore\Examples\...` 命名空间,避免与业务 `App\...` 类冲突。
- 可能被启动阶段注册的示例(AMQP Consumer、AsyncQueue Process、Process、Crontab)都额外加了 `COMMON_CORE_EXAMPLE_ENABLE` 开关,默认 `false`;即使有人误把 `examples/` 加入扫描路径,也不会拉起真实消费者/进程/定时任务。
- HTTP Controller / WS Controller / Aspect 示例**刻意不直接声明** `#[AutoController]` / `#[WsController]` / `#[Aspect]`,避免误扫后注册真实路由或切面;复制到业务项目后再按注释添加。

目录说明:

- `Amqp/`:普通 AMQP Producer / Consumer 示例。
- `Amqp/Dynamic/`:按服务器动态 routingKey / queue 的 AMQP 示例。
- `AsyncQueue/`:AsyncQueue 消费进程和 Job 示例;`AsyncQueue/Dynamic/` 为按服务器动态队列示例。
- `Aspect/`:模块前置切面示例(签名参数、API/后台鉴权分流)。
- `Config/`:业务 `dependencies.php`、`middlewares.php` 关键绑定片段。
- `Http/`:HTTP Controller / Service / Component / ObjectAttribute 示例。
- `Model/`:普通模型和分表模型示例。
- `Process/`:自定义常驻进程示例。
- `TaskCron/`:Crontab 和 Task 参考代码。
- `Tools/`:DcsLock、HttpClient、OpenSsl、AsyncQueue、AMQP、RpcMqCall 常用工具示例。
- `WebSocket/`:WS 绑定策略、握手 Hook、Controller / Service / Component / 配置示例。

使用方式:

1. 复制需要的示例到业务项目 `app/` 下对应目录。
2. 把 namespace 改成业务项目命名空间,例如 `App\Amqp\Consumer`。
3. 按业务环境决定是否保留 `COMMON_CORE_EXAMPLE_ENABLE` 防护开关。
4. 不要直接把 `vendor/dleno/common-core/examples` 加入业务注解扫描路径。
