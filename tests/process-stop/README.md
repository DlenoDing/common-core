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


本地 P2 增量用例（尚未发布）：

- 每个 runtime 都用真实 DI 检查包内 Factory 的纯继承，以及覆写后调用 parent 的应用 Factory，核对 get/make 调用次数和参数透传。
- `queue-shared-drain`：同一真实 Redis driver 同时运行两次 consume，分别取得两条快/慢消息，max_messages 自然收尾。快 consume 必须在自身 ACK/defer 后返回，并早于慢任务完成；外层进程仍等待两者。此项验证共享 driver 的生命周期，不等同于完整 CoroutineServer 启停测试。
- `queue-pending-start`：测试 driver 仅在父 getCallback 返回后加一秒启动延迟，让 pending 可观测；即使 consume 已达到 max_messages，也必须等待 pending、执行及直接 defer，真实 ACK 后 reserved=0。另保留 `queue-reserved-slot` 覆盖实际并发槽位等待。
- `queue-nested-unjoined`：真实 Redis 回调嵌套 parallel，子协程 defer 延迟三秒。预期外层先完成和 ACK、WORKER_EXIT 后子 defer 才结束，明确记录框架边界，不当作嵌套工作已受保护。
- `queue-nested-barrier`：同样的嵌套子工作，在必要 defer 结束后发送完成信号，直接任务显式等待，证明停止期间这类业务可完整收尾后再 ACK/退出。

```sh
STOP_CHECK_FIXTURE=/tmp/common-core-stop-check STOP_CHECK_CASES=queue-pending-start,queue-shared-drain,queue-nested-unjoined,queue-nested-barrier python3 tests/process-stop/run.py
```

历史阶段曾暂停发布；2026-10-25用户已授权正式发布。checkout 回归不能替代正式版本的普通 Composer 安装验证。


正心跳增量：每轮先执行 `heartbeat.php` 的40项无网络配置断言（默认心跳15、读写30、非法值在IO前拒绝、参数透传、调用方数组不变、Factory继承）。`amqp-default-*` 分别覆盖默认15秒心跳的空闲、SIGINT、直接defer、共享consume、两连接/自定义IO/十二秒资源任务、初始化异常和自然maxConsumption；`amqp-positive-heartbeat` 验证显式7秒不被覆盖。`amqp-default-idle` 还省略读写/连接超时，检查框架默认参数组合能实际建连退出。

`amqp-worker-heartbeat` 在真实worker内先取得发布确认，停止后观察框架正心跳关闭连接，不新增HTTPworker信号。它替代已作废的零心跳跨WORKER_EXIT发布用例，不宣称普通后台工作可以跨框架资源清理继续发布。原 `amqp-zero-*` 均已移除；0/负数由无网络配置校验覆盖。

记录器每条 JSON 使用单次 FILE_APPEND，不使用 LOCK_EX。协程 hook 的文件写持锁让出时，非协程 WorkerExit 回调中的同步 flock 会阻塞整个线程。此前仓库外跨 worker 反例中由这类记录器导致的“第二 HTTP 未完成、137”证据已撤回；外部 Redis 读回的锁提前丢失，以及去锁后 Memory/锁提前清理的证据仍有效。修正记录器后再验证 worker 资源生命周期；不能把记录器死锁当作业务退出缺陷。

心跳必须为正整数，省略或null默认15；读写超时省略或null默认30，已有显式值保持不变；应用已有正数、自定义IO和pool保留。源码不再包含 ConnectionExit、ProcessConnectionContext 或额外退出 watcher。旧零心跳结果留在RESULTS/REVIEW历史段，不能当作当前支持0的证明。创建失败两项已知边界按用户的十万以上协程容量约束，本轮不扩展，不能写成已修复。

接收 worker 的 cron 收尾增量：`cron-worker-base`、`cron-worker-process`、`cron-task-worker`、`cron-both-workers`。通过框架原策略和真实 PipeMessage 分发已开始任务，覆盖 BASE/PROCESS、HTTP worker/task worker，按业务所在 PID 检查 native WorkerExit 在任务期间到来、首次父 OnWorkerExit 与 WORKER_EXIT 在直接 defer/解锁后发生，所有父事件仍为 CID=-1。框架原本可能重复发 OnWorkerExit，测试不把它强行改成一次。任务含两次 HTTP、延迟 defer 后第三次 HTTP、Memory、短 TTL 锁续期和真实 AMQP publisher confirm；同一 worker 上的未来任务在退出期间不得开始。应用 WorkerExitCallback 覆盖仍优先，无在途 cron 保持父实现。此组直接调原策略验证接收端退出，分钟触发时机另由原 `cron-boundary` 实测。

`cron-worker-limit` 单独把 Swoole `max_wait_time` 设为两秒、Docker 宽限仍为三十秒。预期出现 worker exit timeout / forced termination，十二秒业务未完成、只有第一个 HTTP；主容器可能仍退出 0，不能按正常收尾判通过。这个负例验证的是 worker 硬上限，与原 `queue-twelve-limit` 的 Docker 十秒 SIGKILL/137/reserved=1 是两种限制。同步 task worker、应用自定义退出回调、任意未等待的后台协程及保活定时器创建失败不在正向退出保证内。cron 资源用例使用应用配置的正心跳；包内工厂已禁止零心跳；HTTP worker 连接由框架正心跳机制关闭，不额外接管其退出。


## 实际生命周期回归

当前新增12项，按实际操作分组运行；仍用同一隔离fixture、临时Redis/Rabbit/HTTP，不改生产实现。

    STOP_CHECK_FIXTURE=/tmp/common-core-stop-check STOP_CHECK_CASES=queue-repeat-term,amqp-repeat-int,amqp-repeat-pid1,cron-repeat-term,amqp-repeat-cleanup-pid1 python3 tests/process-stop/run.py
    STOP_CHECK_FIXTURE=/tmp/common-core-stop-check STOP_CHECK_CASES=cron-max-request-base,cron-max-request-process python3 tests/process-stop/run.py
    STOP_CHECK_FIXTURE=/tmp/common-core-stop-check STOP_CHECK_CASES=broker-pause-inflight,broker-paused-close,broker-network-close,broker-crash-before-stop,broker-paused-close-tight python3 tests/process-stop/run.py

- 二次信号：在途时对同一PROCESS发TERM/TERM或TERM/INT，另向容器PID1重复TERM；cron原生worker也测重复TERM。测试观察器仅记录，包内StopHandler仍只有一个实例，没有增加WORKER信号。清理阶段也向容器PID1重复TERM，不把强杀内部子PID当默认运维路径。
- max_request：只用一个worker固定PID以便观测，实际HTTP /rotate返回200，max_request=1、grace=0、reload_async=true触发真正轮换；旧任务完成，新PID执行后续任务。后继任务在新worker就绪后派发；不从单worker测试推导生产多worker服务可用性。
- MQ临时卡住：在途任务遇Rabbit暂停，确认真实阻塞后恢复，最终ACK和broker回读0/0、没有重复执行。
- MQ持续故障：先完成任务并回读ACK0/0，再暂停MQ或断开隔离网络；关闭卡住和死锁检测是限制，不能算正常退出。broker-crash-before-stop专门验证停止前MQ崩溃的错误回收路径，不代表任何时序的硬断都能完成AMQP关闭握手。十秒窗口137也单列限制，已确认业务无重放。
- 每个故障用例都有固定expected_outcome；未知关闭异常、非预期类别会失败，不把任何0/137都算通过。IO close观测仅表示本地socket方法被调用，不代表关闭握手完成。以deadlock日志、PID与broker确认共同判断。
- 不增加应用保活监控来观察关闭，避免干扰Swoole死锁检测。Rabbit恢复后检查实际应用就绪和list_queues成功，才进入下一项。
- 早期探索的rw=0和直接杀子PID清理窗口已移出常规回归，不作为本轮现实风险重点。旧45项仍保留；默认全跑共57项，可按上述命令只跑有关场景。边界用例“断言匹配”不等于正常退出。
