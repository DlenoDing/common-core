# common-core examples

`examples/` 目录仅放示例代码,不会随 `dleno/common-core` 安装后自动执行。

安全边界:

- `composer.json` 只 autoload `src/`,不加载 `examples/`。
- `ConfigProvider` 只把 `src/` 加入注解扫描路径,不扫描 `examples/`。
- 示例类使用 `Dleno\CommonCore\Examples\...` 命名空间,避免与业务 `App\...` 类冲突。
- 可能被启动阶段注册的示例(AMQP Consumer、AsyncQueue Process、Process、Crontab)都额外加了 `COMMON_CORE_EXAMPLE_ENABLE` 开关,默认 `false`;即使有人误把 `examples/` 加入扫描路径,也不会拉起真实消费者/进程/定时任务。
- HTTP Controller / WS Controller / Aspect 示例**刻意不直接声明** `#[AutoController]` / `#[WsController]` / `#[Aspect]`,避免误扫后注册真实路由或切面;复制到业务项目后再按注释添加。

目录说明:

- `Amqp/`:AMQP 三种调用方式分别示例(Producer/Consumer 各自成对)——
  - 普通调用:`Producer/NormalProducer` + `Consumer/NormalConsumer`(直连交换机、立即投递)。
  - 延时调用:`Producer/DelayProducer` + `Consumer/DelayConsumer`(x-delayed-message 插件,`delayExchange=true`,生产/消费须一致)。
  - 延时到死信调用:`Producer/DelayDlxProducer` → `Consumer/DelayDlxBufferConsumer`(声明带 x-message-ttl + 死信的延时缓冲队列,**无活跃消费者**)→ 过期转投死信 → `Consumer/DelayDlxDeadConsumer` 消费(不依赖延时插件的延时方案)。
- `Amqp/Dynamic/`:按服务器动态 routingKey / queue 的 AMQP 示例。
- `AsyncQueue/`:AsyncQueue 消费进程和 Job 示例;`TestJob` 内 `pushNormalExample()`(普通/立即)、`pushDelayExample()`(延时,push 第二参 $delay)分别演示;`AsyncQueue/Dynamic/` 为按服务器动态队列示例。(redis 异步队列无死信能力,故只有普通 + 延时两种。)
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
