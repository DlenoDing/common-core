# 定向停止验证

在仓库外创建依赖 fixture、内部 Docker 网络、临时 Redis 和 HTTP 替身。默认使用仓库 CI 的 PHP 8.4 / Swoole 6.2.3 镜像，Composer 按包约束解析 Hyperf 3.2。需要 Docker 和 Python 3：

```sh
python3 tests/process-stop/run.py
```

`STOP_CHECK_IMAGE` 可指定等价镜像。`STOP_CHECK_FIXTURE` 可复用此前此脚本生成的 `fixture`（含 vendor 和 composer.lock），不得指向业务应用依赖。默认从 Composer 正常解析依赖。依赖与测试产物保存在脚本打印的临时目录，容器和网络在 finally 中清理；脚本不读取应用配置或凭据。

测试将当前 checkout 只读挂载为 `/source`，直接加载包内信号处理器、BaseQueueConsumer 及 ConfigProvider 的依赖映射，使用真实 Hyperf DI、SignalRegisterListener/SignalDeregisterListener、RedisDriver 和继承的 AbstractProcess::bindServer()。仅以最小 PSR 事件分发器接线，并按 BootProcessListener 的职责设置 running 标记；不加载任何业务 App 覆盖。调度策略为空替身，不执行业务定时任务。

覆盖：定时开关、单个注册标识、包内容器解析、应用依赖覆盖、PROCESS 的 TERM/INT 范围、WS 队列继承的 recvTimeout；调度进程在距离下一分钟超过十秒时收到 TERM/INT；空闲队列十秒停止窗口；七秒 HTTP + 后续 HTTP；十二秒任务三十秒宽限；十二秒任务十秒硬上限。最后一个用例**预期为 137、未 ACK、reserved=1**，属于硬上限限制，不是正常退出成功。Redis 数据只存在于独立临时容器，不会重放该消息。

每个正常用例检查进程结束标记、容器退出码 0、没有错误事件；任务用例同时核对替身收到的请求次数、ACK 事件及 Redis reserved。原始 JSONL、Swoole 日志和结果 JSON 留在临时目录。耗时从调用 Docker stop 前开始，包含 Docker 命令开销；框架原有五秒重启间隔保留。测试没有统一设置 signal.timeout、降低任务/HTTP超时、删除队列或提前 ACK。

边界：本测试没有完整启动业务应用的注解扫描/BootProcessListener，单实例检查覆盖 ConfigProvider 列表、容器实际类及真实绑定的一个进程；应用自行重复注册仍需应用治理。没有 RabbitMQ 实例、AMQP 在途 ACK 测试、真实 WS 注册/推送、协程服务器模式、用户自定义进程或外部服务。Hyperf AMQP 心跳协程收到 WORKER_EXIT 会关闭连接，因此此版本不承诺 AMQP 在途消息在停止期间可确认；需要在目标应用独立验证。WS 注册进程原有 sleep 周期和 AMQP 重试/消费机制保持原样。
