# 定向停止回归

需要 Docker、Python 3 和下载依赖的网络。仓库外建立独立依赖 fixture、内部 Docker 网络、临时 Redis、RabbitMQ 及 HTTP 替身；不用业务配置/凭据，不修改消费者 vendor，不部署应用。

```sh
python3 tests/process-stop/run.py
```

默认镜像与仓库 CI 一致：PHP 8.4 / Swoole 6.2.3，Composer 按包约束解析 Hyperf 3.2。可用 `STOP_CHECK_IMAGE` 指定等价镜像；`STOP_CHECK_FIXTURE` 可复用脚本先前创建的 fixture（vendor/composer.lock），不得指向业务应用依赖。`STOP_CHECK_CASES` 用逗号分隔用例名，只运行指定回归；默认执行全部。

当前 checkout 以只读方式挂载 `/source`，PSR-4 优先加载当前包，反射核对源码路径。fixture 内 src 软链接只服务测试自依赖，不代表正式 Composer 安装验证。使用真实 Hyperf Provider 合并（AMQP 两种加载顺序）、DI、应用覆盖优先级、EventDispatcher、Signal 注册/注销、BootProcessListener、ConsumerManager 创建的实际 AMQP 进程及继承的 AbstractProcess::bindServer。不会以 App 覆盖或裸协程回调代替包的进程退出证明。

用例覆盖空闲 TERM/INT、标准三种跨进程调度策略、真实分钟边界分发、父 handle 抛异常后的 watcher 收尾；Redis 单/并发任务、槽位已满但消息已 reserved、原 retry 与 max_messages 收尾；真实 RabbitMQ 串行及十二秒并发消费。任务通过慢 HTTP 再发第二个 HTTP，确认不重发；资源用例持有短 TTL 的框架 task/server mutex、DcsLock 和 Memory 缓存，停止后核对锁所有者、竞争者无法取得以及 AMQP publisher confirm。

正常用例要求容器退出 0、任务执行一次，Redis ACK/retry 与业务收尾先于 WORKER_EXIT；RabbitMQ 直接读取 ready/unacked，证明 ACK 过线。既有框架不主动取消 broker 预取：已开始任务需确认一次，未开始的消息允许连接关闭后返回，不将其误判为业务重复执行。

框架原五秒重启间隔保留。七秒任务的十秒窗口用例在慢请求执行三秒后停止；另有七秒任务刚开始就停止、三十秒宽限的用例。十二秒任务的十秒硬上限用例预期 137、未 ACK、reserved=1，属于限制复现，不是正常退出通过。不改 handle_timeout、retry_seconds，不清队列、不提前 ACK、不因停机重发任务。

`CoroutineStrategy` 及自定义策略保留框架等到分钟边界的行为：用二十秒真实在途任务跨边界，并另测停止后边界批次的 singleton/onOneServer 八秒任务、尚未到点的秒级 future 任务。包内 Executor 在加锁前计数、整个协程 defer 完成后才减计数，调度进程等待已开始工作结束后才清理；这组用例直接检查锁续期、业务完成及 future 任务关闭而不执行。共享协程服务器模式通过源码 guard 审核，不在真实服务器回归范围；应用自定义监听器、AOP、driver、Consumer 和后台协程同样需要独立评估。WS 两个消费者检查继承属性及默认驱动，未启动完整 WS 业务；注册进程 sleep 周期保持原样。

脚本打印临时产物目录，保留原始 JSONL、Swoole 日志、broker 队列状态、结果 JSON；finally 清理测试容器和网络。默认不把这组较慢的真实停止回归塞入 composer test；后者仍只是语法检查。正式版本发布后的无 path/软链接 Composer 安装需另行验证。

负向对照：`STOP_CHECK_UNSAFE=1 STOP_CHECK_CASES=amqp-serial python3 tests/process-stop/run.py` 仅在测试容器复现 v3.2.3 的提前唤醒，预期测试失败；不修改包或 vendor。另有 queue-defer/amqp-defer，用真实延迟 defer 后续 HTTP 验证仅等待 Concurrent 槽位不足以保证清理顺序。
