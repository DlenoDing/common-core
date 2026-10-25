# 通用进程退出修复回归记录

> 发布执行（2026-10-25）：用户已明确授权打包发布，已核对远端及Packagist最新v3.2.4，选择未占用v3.2.5。此前“暂停发布”均为历史阶段；本次不升级消费者、不修改其vendor或部署业务。最终发布与正常Composer安装结果另行交付。

> 最新实际场景复验（2026-10-25）：二次信号、真实max_request轮换、MQ故障回归已补齐。最终12项为7项正常收尾、1项停止前MQ崩溃的错误回收对照、4项明确故障限制，不是12项都正常关闭。生产源码未改，只补测试和说明；不再扩展极端参数或纯理论组合。继续不提交、不打包发布。

> 最新全量复验（2026-10-25）：当前15/30源码重新通过40项配置检查和45项停止回归（43正常、两项硬上限、HTTP120）；Claude独立六项及补充验证通过，本用户范围内未发现新增P1/P2。只修订说明与验证记录，生产源码/仓库测试逻辑未改。已知容量失败和框架资源行为仍按原边界保留，继续不提交、不打包发布。

> 最新用户范围（2026-10-25）：用户提供最大协程数至少十万的运行约束，两项创建失败问题本轮不扩展，仍是已知未修边界。AMQP按用户最新要求禁止0、心跳缺失默认15秒、读写超时缺失默认30秒，旧零心跳watcher/PID方案已撤除；以下零心跳实现与反例为历史记录。15/30秒正心跳新回归及独立互审已通过，最新增量见文末，继续暂停提交、打包和发布。

> 再次完整复验（2026-10-25）：新增反例确认两条 P2（串行 pending 泄漏、并发槽位泄漏后无限等待）及一项 P3（零心跳 watcher 保留已关闭连接）。当前未发布工作区仍需修复；此前“无未解决 P1/P2”结论仅对应旧覆盖范围，已被本轮更新。继续暂停提交、打包和发布。

> 上一轮增量（已由本轮更新）：已按用户授权完成已有 cron 延后清理及零心跳 PROCESS 修复；完整45项（43项正常、两项硬上限边界）和双 worker 夹具稳定性增量已完成，Claude 最终互审已一致，范围内未发现未解决 P1/P2。仍暂停提交、打包和发布。
> 前轮范围说明（本段为授权前历史记录）：后续 Claude 已确认退出日志中的 LOCK_EX 会污染跨 worker HTTP/137 反例，这部分证据撤回；资源提前清理仍有效。零心跳独立 PROCESS 修复与新验证另见文末。跨 worker cron 的退出约束尚未获准放宽，不能声称两项 P2 均已完成。

> 以下原有记录对应已发布 v3.2.4（b22fa78）。后续审计确认了 Factory 自委托及 Redis 共享 consume 收尾两项 P2；本轮增量证据另见文末。此前“可发布”结论不能用于本轮未发布修改。

日期：2026-10-25（Asia/Kuala_Lumpur）。修复基线：`c0e4e4759903e897b306b7057575f0bb4d5d5e72`（v3.2.3）。

本轮修正“收到停止信号就触发全局资源清理”，并补齐 Concurrent/Parallel 的槽位释放早于 defer、AMQP 并发收尾五秒限制，以及同进程定时任务跨分钟清理的路径。未改业务系统或消费者 vendor。

环境：PHP 8.4.27、Swoole 6.2.3；Hyperf process/crontab 3.2.0、async-queue 3.2.2、amqp 3.2.5、coordinator 3.2.1.1；临时 Redis 7.4、RabbitMQ 4.4.0、Swoole HTTP 替身。代码直接只读挂载当前 common-core，未用 App 覆盖来证明正向修复。

完整命令：`STOP_CHECK_FIXTURE=/tmp/common-core-stop-check python3 tests/process-stop/run.py`。27 项全部满足各自断言；其中 137 用例是预期硬上限边界，不属于正常退出成功。

| 用例 | 宽限秒 | 停止耗时秒 | 退出码 | reserved | 任务数 / HTTP 次数 |
| --- | ---: | ---: | ---: | ---: | ---: |
| cron-term | 10 | 5.828 | 0 | 0 | 0 / 0 |
| cron-int | 10 | 5.851 | 0 | 0 | 0 / 0 |
| cron-task-strategy | 10 | 5.882 | 0 | 0 | 0 / 0 |
| cron-process-strategy | 10 | 5.902 | 0 | 0 | 0 / 0 |
| cron-fault | 15 | 6.126 | 0 | 0 | 0 / 0 |
| queue-idle | 10 | 6.997 | 0 | 0 | 0 / 0 |
| queue-seven | 10 | 9.171 | 0 | 0 | 1 / 2 |
| queue-seven-early | 30 | 12.069 | 0 | 0 | 1 / 2 |
| queue-twelve-grace | 30 | 17.130 | 0 | 0 | 1 / 2 |
| queue-twelve-limit | 10 | 10.165 | 137 | 1 | 1 / 1 |
| queue-resources | 30 | 12.139 | 0 | 0 | 1 / 2 |
| queue-parallel | 30 | 12.142 | 0 | 0 | 2 / 4 |
| queue-reserved-slot | 30 | 11.103 | 0 | 0 | 2 / 4 |
| queue-serial-defer | 15 | 8.230 | 0 | 0 | 1 / 3 |
| queue-defer | 15 | 8.091 | 0 | 0 | 2 / 6 |
| queue-retry | 15 | 7.182 | 0 | 0 | 1 / 2 |
| queue-max-messages | 15 | 5.099 | 0 | 0 | 2 / 4 |
| amqp-idle | 15 | 9.767 | 0 | 0 | 0 / 0 |
| amqp-serial | 20 | 12.139 | 0 | 0 | 1 / 2 |
| amqp-serial-defer | 15 | 10.096 | 0 | 0 | 1 / 3 |
| amqp-defer | 15 | 10.082 | 0 | 0 | 2 / 6 |
| amqp-shared | 20 | 13.100 | 0 | 0 | 2 / 6 |
| amqp-parallel | 30 | 20.173 | 0 | 0 | 2 / 4 |
| cron-boundary | 10 | 5.539 | 0 | 0 | 0 / 0 |
| cron-coroutine-boundary | 45 | 25.188 | 0 | 0 | 1 / 2 |
| cron-locked-batch | 40 | 28.513 | 0 | 0 | 1 / 2 |
| cron-custom-boundary | 45 | 25.189 | 0 | 0 | 1 / 2 |

耗时从调用停止命令前计，包含 Docker 命令开销。queue-seven 在七秒慢请求已经运行三秒后停止；queue-seven-early 在请求刚开始即停止，保留五秒框架重启间隔后约十二秒结束。AMQP 原匿名进程仍使用框架十秒管道读取等待，未篡改其 bindServer 或重启周期，因此空闲样本约9.8秒；不能承诺所有应用十秒退出。

正常任务断言 job-start < running-false < job-done，ACK/retry、defer 和主动解锁均先于 WORKER_EXIT。max_messages 是没有外部停止信号的另一种收尾触发，单独按任务先完成验证。AMQP 消费来自实际 ConsumerManager 创建的 PROCESS，直接读 broker ready/unacked=0；已执行消息无 redelivered、没有重复执行业务。

资源用例包含短 TTL 的框架 task/server mutex 与 DcsLock，TTL后竞争者加锁失败；Memory 缓存保留，停止后两次 HTTP 及 AMQP publish confirm 成功。另用三十秒 TTL 的 DcsLock 在 defer 回读确认主动解锁，避免把自然过期当正常释放。串行（没有 Concurrent）与并发（limit=2）四条 defer 路径均用延迟 IO 证明清理顺序。

定时验证包括原 WorkerStrategy 的真实分钟分发、其他两个精确白名单、父 handle 异常后 watcher 结束、二十秒工作跨分钟、八秒 singleton/onOneServer 的分钟边界批次。future-probe 秒级任务在清理时由框架 close，没有提前执行；框架对此输出的既有“failed execution”日志在该用例单独核对，其他错误不放行。共享 Consumer 两个 consume 实例在同进程并行，快的达到 maxConsumption 后先于慢的结束返回，未被另一个桶的计数拖住；这不是完整共享 CoroutineServer 的集成证明。

负向对照：

- 恢复 v3.2.3 提前全局唤醒（仅 fixture，不改包/vendor）后，amqp-serial 测试按预期失败，业务完成后 ACK 抛 AMQPChannelClosedException，broker ready=1、unacked=0。
- 只等 Concurrent 槽位的中间实现，queue-defer 按预期失败：ACK 后先出现 WORKER_EXIT，随后 defer 报 Resources closed inside task defer，后续 HTTP 缺失。最终实现追踪完整回调协程而非仅槽位。

原始证据：

- 完整27项：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-1bfizygd`，含 results.json、各用例 events.jsonl、broker-queues.txt 和 container.log。
- 提前唤醒负向：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-2c2mtbrm`。
- Concurrent-only defer 负向：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-ipvo_kkb`。

另通过 composer validate --strict、composer test（源码语法检查）、测试PHP语法检查、PHPStan level 1、git diff --check。语法检查不是停止行为证据。测试容器/网络均在 finally 清理。

全组之后发现并修补一项兼容接入：AMQP PriorityDefinition 不能静默绕过应用既有 ConsumerFactory 覆盖，包内 factory 现优先委托应用 factory。隔离 DI 对照 factory-compat-before.log/after.log 显示从错误解析包内 Consumer 修正为 AppConsumer；默认 AMQP 链路另作定向复跑。另补足应用 Factory 的 make() 可选参数透传并固化 DI 断言；最终互审及定向结果见 [REVIEW.md](REVIEW.md)。

边界：不强制取消任务，不改变 handle_timeout 到期的原恢复语义；应用自定义 driver/Consumer/Executor/Factory 显式覆盖需复核是否继承 drain。未追踪的应用后台协程、完整 WS 业务、真实共享 CoroutineServer 生命周期不作保证。框架协程创建失败时可能残留 Concurrent 槽位的既有问题未改，容器硬停止宽限仍是外部兜底。正式发布后的无 path Composer 安装另行验证，不能用本机 checkout 替代。


## 两项 P2 增量修复与重新验证（2026-10-25，未发布）

基线为 v3.2.4 / `b22fa78a0d94f5d13f2b430e46404b97e8fc3284`。本轮仅改两个生产文件：Factory 排除向自身委托，保留应用继承/override/parent 调用及 make 参数；RedisDriver 将 prepared/running callback 按 consume owner 计数，独立等待自身 ACK/retry/直接 defer。生产消费循环、确认、重试、恢复、锁和调度规则未重写。

环境 PHP 8.4.27、Swoole 6.2.3；Hyperf process/crontab 3.2.0、async-queue 3.2.2、amqp 3.2.5、coordinator 3.2.1.1。独立 Docker 内部网络、临时 Redis 7.4/RabbitMQ 4.4.0、HTTP 替身。直接加载 checkout 包实现并反射核对源码路径，未修改消费者 vendor 或部署业务应用。

命令：`STOP_CHECK_FIXTURE=/tmp/common-core-stop-check python3 tests/process-stop/run.py`。本轮完整 31 项断言全部成立；queue-twelve-limit 是预期 SIGKILL 限制，queue-nested-unjoined 是预期子 defer 未受外层等待保护的边界观察，均不能表述为所有工作已正常收尾。

| 用例 | 停止宽限秒 | docker stop 耗时秒 | 退出码 | reserved | 任务 / HTTP |
|---|---:|---:|---:|---:|---:|
| cron-term | 10 | 5.822 | 0 | 0 | 0 / 0 |
| cron-int | 10 | 5.884 | 0 | 0 | 0 / 0 |
| cron-task-strategy | 10 | 5.842 | 0 | 0 | 0 / 0 |
| cron-process-strategy | 10 | 5.832 | 0 | 0 | 0 / 0 |
| cron-fault | 15 | 6.150 | 0 | 0 | 0 / 0 |
| queue-idle | 10 | 6.915 | 0 | 0 | 0 / 0 |
| queue-seven | 10 | 9.146 | 0 | 0 | 1 / 2 |
| queue-seven-early | 30 | 12.123 | 0 | 0 | 1 / 2 |
| queue-twelve-grace | 30 | 17.246 | 0 | 0 | 1 / 2 |
| queue-twelve-limit | 10 | 10.158 | 137 | 1 | 1 / 1 |
| queue-resources | 30 | 12.165 | 0 | 0 | 1 / 2 |
| queue-parallel | 30 | 12.058 | 0 | 0 | 2 / 4 |
| queue-reserved-slot | 30 | 11.102 | 0 | 0 | 2 / 4 |
| queue-serial-defer | 15 | 8.126 | 0 | 0 | 1 / 3 |
| queue-defer | 15 | 8.199 | 0 | 0 | 2 / 6 |
| queue-retry | 15 | 7.290 | 0 | 0 | 1 / 2 |
| queue-max-messages | 15 | 5.199 | 0 | 0 | 2 / 4 |
| queue-pending-start | 15 | 5.096 | 0 | 0 | 2 / 6 |
| queue-shared-drain | 20 | 10.202 | 0 | 0 | 4 / 12 |
| queue-nested-unjoined | 15 | 7.074 | 0 | 0 | 2 / 4 |
| queue-nested-barrier | 15 | 9.248 | 0 | 0 | 2 / 4 |
| amqp-idle | 15 | 9.763 | 0 | 0 | 0 / 0 |
| amqp-serial | 20 | 12.150 | 0 | 0 | 1 / 2 |
| amqp-serial-defer | 15 | 10.006 | 0 | 0 | 1 / 3 |
| amqp-defer | 15 | 10.082 | 0 | 0 | 2 / 6 |
| amqp-shared | 20 | 13.093 | 0 | 0 | 2 / 6 |
| amqp-parallel | 30 | 20.095 | 0 | 0 | 2 / 4 |
| cron-boundary | 10 | 5.514 | 0 | 0 | 0 / 0 |
| cron-coroutine-boundary | 45 | 25.211 | 0 | 0 | 1 / 2 |
| cron-locked-batch | 40 | 28.522 | 0 | 0 | 1 / 2 |
| cron-custom-boundary | 45 | 25.202 | 0 | 0 | 1 / 2 |

耗时从 docker stop 命令前计，包含原有五秒重启间隔；max_messages/pending-start 的自然收尾在停止命令前已完成，因此其约五秒不是任务总时长。共享 Redis 用例仍在任务执行中发停止信号，max_messages 的独立收尾顺序另从原始事件验证。

新增四项的第一次独立主方验证：pending-start 5.106s、shared-drain 10.212s、nested-unjoined 7.184s、nested-barrier 9.147s。共享 fast 自身 defer 后约 2.040s 返回，slow 首次业务完成约 4.119s；两次 consume 未互相阻塞，外层进程仍等待两者。嵌套未等待用例在进程 ready 后约 2.036s 触发 WORKER_EXIT，约 3.02s 子 defer 才结束；barrier 用例子 defer 约 3.012s 完成、业务与 ACK 约 4.053s 完成，随后退出。原始 ACK 时机仍由框架决定，没有为 defer 提前或延后 ACK。

每个 runtime 均验证 Factory 纯继承、override 调 parent、get/make 调用次数和参数透传；旧应用 Factory/Consumer 直接覆盖及 Provider 双顺序验证也保留。资源用例确认锁续期、主动解锁、Memory、第二 HTTP、停止后 AMQP publish confirm；消费 broker ready/unacked=0，无业务重发。PHP 语法、Python AST、两处源码的 PHPStan level 1 和 git diff --check 通过。

证据：

- 主方完整 31 项：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-8wzx_alo`（results.json、每项 events.jsonl/container.log/broker-queues.txt、HTTP JSONL）。
- 主方新增四项：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-8kadwy2v`。
- Codex CLI 独立九项：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-lbi_jzfl`；独立源码与原始时序报告 `/tmp/common-core-p2-review/review.md`。
- 两项旧版反例与审计：`/tmp/common-core-reaudit/factory-subclass-cycle.log`、`redis-shared.jsonl`；本轮源码/测试 SHA-256：`/tmp/common-core-p2-review/source-manifest.json`。

边界：不保证任意子协程 defer；parallel 返回前的 WaitGroup 完成不等于子 defer 完成，必要收尾由业务显式等待。自定义 callback 装饰器丢弃父回调/调用前异常会遗留 prepared 计数；协程创建失败、强制取消、无限阻塞仍需外部宽限，未注入这些异常作真实回归。完整 CoroutineServer、WS 业务、应用 AOP/自定义扩展未验证。共享 driver 测试不能当作完整共享服务器集成证明。

按用户最新要求，本轮不打包、不创建提交/tag、不推送、不发布。没有本轮正式版本和普通 Composer 安装结果；checkout/fixture 不代表正式包可用，后续获准发布后仍需正常 Composer dist 安装验证。消费者升级清理清单仍见主 README；当前没有实施业务升级或清理消费者文件。


## AMQP 零心跳独立 PROCESS 修复（2026-10-25，未发布）

本轮保留此前 Factory 自委托、Redis consume owner/pending 两处修正。新增包内 ConnectionFactory 调用 parent::make，零心跳只补 WORKER_EXIT watcher，借 ConnectionExit 沿用框架正常关闭状态和握手；Before/AfterProcessHandle 标记当前 PID，watcher 醒来时才检查，避免 HTTP/task worker、CoroutineServer 和命令被新增关闭。应用心跳、IO、连接参数、池、ACK/retry/任务超时和五秒重启间隔未改。

先完成 Claude 方案互审，再写源码：B2 批准见 `/tmp/common-core-p2-rootfix/claude-supplement.md`；PROCESS 范围及 After 事件异常补救批准见 `claude-scope.md`。主方九项定向结果 `/tmp/common-core-p2-rootfix/amqp-scoped.log`，全部满足断言；随后对冻结的源码和测试执行完整四十项。

完整命令仍为 `STOP_CHECK_FIXTURE=/tmp/common-core-stop-check python3 tests/process-stop/run.py`。PHP 8.4.27、Swoole 6.2.3、Hyperf 3.2，临时 Redis/RabbitMQ/HTTP、Docker 内部网络、只读源码加载，原始事件直接验证包实现。40 项断言均成立：39 项退出 0，唯一 137 为预期 queue-twelve-limit；nested-unjoined 仍是预期边界观察，不属于任意子协程已受保护的证明。两个故障用例只允许对应的预期初始化/调度异常，future 关闭沿用框架行为。

本轮新增九项完整复跑数值：

| 用例 | 宽限秒 | docker stop 耗时秒 | 退出码 | 任务 / HTTP |
|---|---:|---:|---:|---:|
| amqp-zero-idle | 15 | 9.924 | 0 | 0 / 0 |
| amqp-zero-int | 15 | 9.862 | 0 | 0 / 0 |
| amqp-omitted-idle | 15 | 9.826 | 0 | 0 / 0 |
| amqp-zero-defer | 15 | 10.101 | 0 | 1 / 3 |
| amqp-zero-shared | 25 | 13.128 | 0 | 2 / 6 |
| amqp-zero-parallel | 30 | 20.098 | 0 | 2 / 4 |
| amqp-zero-worker-publisher | 15 | 2.169 | 0 | 1 / 0 |
| amqp-zero-setup-fault | 15 | 5.151 | 0 | 0 / 0 |
| amqp-zero-max | 15 | 8.050 | 0 | 1 / 3 |

完整产物：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-0s4nub_x`。40 项 HTTP 原始计数 101，与每项汇总一致。每项保留 events、容器/Swoole 日志、broker 读回。四十项运行时冻结的源码/脚本 SHA-256 manifest：`/tmp/common-core-p2-rootfix/source-manifest-full40.json`，执行中未变；PHP/Python 语法与三处新增源码 PHPStan level 1 通过。

- zero/omit/INT 真正读取的 heartbeat=0，未改配置；zero-parallel 的两个连接都经过自定义 IO，read_write_timeout=25 保留。
- zero-shared 的 fast 返回早于 slow 业务完成；关闭发生在整个 PROCESS 的 callback/defer 完成后，消费队列 ready/unacked=0。
- zero-parallel 的十二秒任务在停止后保持锁、Memory、第二 HTTP 和 publisher confirm；消费队列 0/0，publish 队列 2/0。
- zero-worker-publisher 是 HTTP worker 内协程兼容性测试：WORKER_EXIT 后，已有连接与新建连接各得到 confirm，证明未新增 worker 连接关闭；最后由测试显式关闭，并不冒充已修复 HTTP worker 的零心跳退出限制。
- zero-setup-fault 在 PID 标记前建连接后抛异常，AfterProcessHandle 补记 PID，正常退出。zero-max 自然达到 maxConsumption 后，原确认和 defer 流程先结束。
- Claude 独立运行四项当前源码（shared/parallel/worker-publisher/setup-fault），全部退出 0，broker 与原始时序核对一致；产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-zgqczfq2`，报告 `/tmp/common-core-p2-rootfix/final-independent-review.md`。

### 跨 worker 证据更正与未完成边界

原先仓库外 workerExit 回调使用 LOCK_EX 记录事件，非协程回调会与协程文件 IO 争锁，造成观察器死锁。Claude 用纯 Swoole 对照确认：退出回调不加锁时 41/41 条任务记录完成、exit 0；加锁时冻结、exit 137。因此旧报告中“第二 HTTP 不完成、2 秒任务配 20 秒或 12 秒配 35 秒仍 137”证据撤回，不能再用来归因业务退出。

去掉记录器锁后，真实框架回调的六秒任务可以完成两次 HTTP，但任务的 Memory 已清空、框架 task/server mutex 在约 5.6 秒已丢失；原始 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-nfca3w6b`。所以 P2-A 的真实缺陷仍是“接收 worker 过早清理在途 cron 资源”，进程退出 0 不代表业务验证通过。

Claude 实测 OnWorkerExit 事件处于 CID=-1：Coroutine::sleep 不可用，usleep 会阻塞 reactor；延后框架 WorkerExitCallback 的 wrapper 正向原型能保留资源，但与用户不额外接管 HTTP Worker 退出的约束冲突。当前已请求用户明确是否允许仅为已有 cron 任务延后清理，未获答复前不修改该源码。独立报告 `/tmp/common-core-p2-fix-review.md` 及补充/最终报告均明确该限制。应用 Swoole max_wait_time 也是独立硬上限，容器宽限不能替代，本包未改默认值。

当前只对 P2-B（AMQP 零心跳独立 PROCESS）收口，不声称两项 P2 均完成；没有正式版本、打包、提交、Git tag、push、发布、消费者 vendor 修改或业务部署。正式 Composer dist 安装留待以后获准发布时执行。AOP、自定义不走包工厂的实现、完整 CoroutineServer、网络背压/半开 broker、真实业务仍非已验证范围。


最终测试接线增量：将夹具手动注册监听器改为框架 ListenerProviderFactory 读取真实 ConfigProvider.listeners，并断言 PROCESS context 只配置一次。生产源码相对四十项完全未变；补跑 queue-seven、zero-idle、zero-worker-publisher、zero-setup-fault，分别 9.131s、9.819s、2.161s、5.264s，均退出 0，真实 ACK/publishconfirm 与范围断言通过。产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-dljzuf2u`；当前源码/测试清单 `/tmp/common-core-p2-rootfix/source-manifest.json`。该增量防止夹具直接硬注册 Context 而掩盖包的 ConfigProvider 漏接，并非更换被测生产实现。


## 已授权的 cron 接收 worker 修复与最终整组（2026-10-25，未发布）

用户明确允许仅为已有 cron 延后框架退出清理。先由 Claude 审方案，主方纠正 Event::defer 不能保证原生上下文的假设，Claude 以 Swoole BASE/PROCESS PoC 复核后批准原生 10ms tick 保活方案，才修改源码。批准报告 `/tmp/common-core-cron-exit-fix/claude-plan-final.md`。

新增 WorkerExitCallback 普通 DI 映射，仅已有包内 Executor 任务时保活并延后 parent；任务计数包含加锁前等待、直接 finally/defer。该 worker 在退出期间不再启动尚未开始的 cron；未开始定时任务沿原完成/关闭通路收尾，不执行或重发。归零后 parent 仍从 native CID=-1 调用；没有 cron 及 drain 后保留框架原有重复事件语义，不强制 once。未增加 HTTP Worker 信号、不改任务分发/分钟调度、heartbeat、业务请求超时、retry、ACK、恢复或 max_wait_time 默认值。

完整45项命令：`STOP_CHECK_FIXTURE=/tmp/common-core-stop-check python3 tests/process-stop/run.py`。产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-ve58eiv6`；日志 `/tmp/common-core-cron-exit-fix/full.log`；运行快照 `/tmp/common-core-cron-exit-fix/source-manifest-full45.json`。43项正常收尾（均exit0），两项按预期复现硬上限；不能把45项都称作正常退出。原始HTTP共117条，与逐项计数总和相符，已开始业务没有重复执行。以下为关键条目，完整逐项数据见产物 results.json：

| 用例 | 停止窗口秒 | 实际停止秒 | 退出码 | HTTP数 | 解释 |
| --- | ---: | ---: | ---: | ---: | --- |
| cron-worker-base | 30 | 14.924 | 0 | 3 | 正常收尾 |
| cron-worker-process | 35 | 20.035 | 0 | 3 | 正常收尾 |
| cron-task-worker | 35 | 14.971 | 0 | 3 | 正常收尾 |
| cron-both-workers | 35 | 22.076 | 0 | 6 | 正常收尾 |
| cron-worker-limit | 30 | 4.165 | 0 | 1 | Swoole内部强制终止；任务未完成 |
| cron-term | 10 | 5.912 | 0 | 0 | 正常收尾 |
| cron-int | 10 | 5.846 | 0 | 0 | 正常收尾 |
| queue-idle | 10 | 6.953 | 0 | 0 | 正常收尾 |
| queue-seven | 10 | 9.131 | 0 | 2 | 正常收尾 |
| queue-twelve-grace | 30 | 17.164 | 0 | 2 | 正常收尾 |
| queue-twelve-limit | 10 | 10.161 | 137 | 1 | Docker强杀，未ACK/reserved=1 |
| queue-pending-start | 15 | 5.191 | 0 | 6 | 正常收尾 |
| queue-shared-drain | 20 | 10.162 | 0 | 12 | 正常收尾 |
| amqp-zero-idle | 15 | 9.762 | 0 | 0 | 正常收尾 |
| amqp-zero-shared | 25 | 13.194 | 0 | 6 | 正常收尾 |
| amqp-zero-parallel | 30 | 20.115 | 0 | 4 | 正常收尾 |
| amqp-zero-worker-publisher | 15 | 2.078 | 0 | 0 | 正常收尾 |
| cron-coroutine-boundary | 45 | 25.162 | 0 | 2 | 正常收尾 |
| cron-locked-batch | 40 | 28.500 | 0 | 2 | 正常收尾 |
| cron-custom-boundary | 45 | 25.156 | 0 | 2 | 正常收尾 |

两类硬上限不同：Docker 十秒截断十二秒 Redis 任务，exit137、reserved=1、未ACK；Swoole max_wait_time=2 即使 Docker 给30秒也强制终止 worker/manager，十二秒 cron 未完成、仅首个HTTP，master仍可exit0。后者必须核对日志及业务事件，不能按容器退出码当正常成功。

独立 Claude 实际复跑 PROCESS十二秒cron、task worker、零心跳共享AMQP三项（19.886 / 14.982 / 13.214秒，exit0），另独立复跑双worker与worker硬上限（21.972 / 4.174秒）。产物分别为 `common-core-stop-mww3ik0e`、`common-core-stop-09us5uzi`，父目录与上方产物一致；报告 `/tmp/common-core-cron-exit-fix/claude-implementation-review.md`、`claude-boundaries.md`。核对原始 HTTP、broker ready/unacked、资源/锁、native回调和直接defer时序，非仅采信PASS。

夹具纠正：双worker最初三秒future在task worker收到退出前合法执行，测试误判，已修正时序；worker硬上限最初错误要求process-exit事件，Swoole manager会截断其原五秒收尾，已改成单独负例断言。之后Claude发现双worker十秒future余量小，最终将该用例future移至二十秒，保留单worker三秒future直接覆盖stopStarting guard。生产源码完全未变；完整45使用十秒future快照，最终稳定性增量只重跑受影响双worker：22.101秒、exit0、两任务六HTTP、原生回调在各自任务期间、收尾后再清理、future未执行，产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-s1xxzxc1`。最终源码/测试快照 `/tmp/common-core-cron-exit-fix/source-manifest-final.json`，不混称两次运行。

PHP8.4语法、Python AST、git diff --check通过；新增WorkerExitCallback和修改Executor的PHPStan level1通过。同步task worker、完整CoroutineServer启停、完整WS业务、应用自定义退出回调/Executor/未等待后台协程及保活创建失败未作正向保证；WS注册sleep15秒和既有重试/恢复均保留。HTTP worker零心跳仍由应用原生命周期管理，未新增关闭机制；cron资源正例使用正心跳，PROCESS零心跳由独立用例覆盖。

当前仍是本地未提交修改；基线 b22fa78a0d94f5d13f2b430e46404b97e8fc3284/v3.2.4。没有新提交、tag、正式版本或Composer正式安装声明；隔离fixture不是普通正式包安装。消费者文件/vendor未改、业务未部署。未来获准发布且正常Composer安装、读回生效后，才能清理应用临时退出覆盖/重复配置与旧DI代理缓存；保留应用业务超时恢复逻辑、心跳和业务参数。


## 再次完整复验：新增反例与修复阻断（2026-10-25）

本轮重新从仓库规则、main/HEAD和当前工作区源码开始；HEAD仍为 b22fa78a0d94f5d13f2b430e46404b97e8fc3284/v3.2.4。开始时14个文件快照 `/tmp/common-core-repeat-audit/initial-manifest.json`；整个实测和互审期间源码/测试/文档字节均未变，结束后仅追加本轮验证记录与README待修提示，没有改生产源码或消费者。

同一最终脚本快照完整45项重新执行，43项正常、两项既有硬上限，原始HTTP117条与逐项合计一致。日志 `/tmp/common-core-repeat-audit/full.log`，产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-66361bhj`，汇总 `/tmp/common-core-repeat-audit/summary.json`。该次包含最终二十秒双worker future夹具，不复用上轮结果。新增源码范围PHPStan level1、git diff --check通过。Claude全新会话619396d1-40eb-43bc-ab67-5f62db439ad1独立跑6项（双worker、PROCESS cron、零心跳并发/初始化异常、共享Redis/pending-start），另跑cron硬上限和BASE，共8项，均满足各自断言。

常规PASS没有覆盖以下反例。Claude首轮无P1/P2报告在补充独立实测后撤回，不能继续作为放行依据。

### P2：串行 prepared 回调创建协程失败后 pending 永不释放（本地新增回归）

RedisDriver:getCallback先增加owner计数，减计数的defer在尚未执行的闭包内。Swoole瞬时达到max_coroutine时，Engine把false赋给?int抛TypeError；父Driver捕获并继续，但该回调永远没有协程执行。串行Parallel的异常发生在wg->wait之前，不能统称框架已有WaitGroup死锁。容量随后恢复也无法解除finally等待。

主方真实Redis对照（capacity-real.py/runtime-capacity.php，临时子driver仅注入短暂协程容量耗尽，consume/ACK仍是实际父实现，BaseQueueConsumer仍直接包内类）：

| 对照 | 停止秒 | 退出码 | reserved | 开始业务/HTTP |
| --- | ---: | ---: | ---: | ---: |
| Hyperf driver | 6.943 | 0 | 1 | 0/0 |
| 当前包内 driver | 10.200 | 137 | 1 | 0/0 |

容量在停止前已恢复。产物 common-core-stop-2yza6gvr，日志 `/tmp/common-core-repeat-audit/capacity-real.log`。Claude独立重跑同一真实Redis脚本，分别7.089秒/0与10.176秒/137，产物 common-core-stop-znmfbcb8，日志 `/tmp/common-core-repeat-audit/claude-poc/capacity-real-rerun.log`。reserved均保留且不提前ACK，区别是包内计数导致无法正常结束。

Claude还用独立真实父consume且不发停止信号验证max_messages：framework和HEAD串行能返回；当前工作区在后续消息完成后仍卡住。故此项不只是停止窗口不足，也能导致消费进程活着但自然重启收尾永久停摆。该瞬时故障不能再作为可接受“框架原边界”排除。

### P2：并发创建失败留下槽位，包内无限等待（HEAD已有同类缺陷）

Concurrent先push槽位再创建协程，失败后槽位没有pop。框架原消费循环捕获创建错误；包内Redis/AMQP收尾把这个无对应协程的占位当作仍有在途工作。Claude framework/HEAD/worktree并发对照，以及AMQP waitConcurrentHandled探针已独立复现：框架Redis自然返回，HEAD与工作区都卡住；框架AMQP约6.72秒有界返回，包内持续等待到测试超时。不能通过恢复五秒硬上限来修复，否则会再次截断真实在途任务。应修正调度失败的槽位/owner记账，同时保留真正pending、running及direct-defer收尾。

### P3：零心跳 watcher 在非PROCESS保留已关闭连接（本地资源回归）

ConnectionFactory每次零心跳make都启动无限yield的协程，强引用connection；isProcess只在WORKER_EXIT醒来后判断。关闭、unset及GC不会释放这个连接，直到WORKER_EXIT。

主方实际Rabbit六轮对照：framework始终1个根协程、0个存活连接；package由2增至7个协程、存活连接由1增至6，process_context=false。主方再resume之后恢复1/0，证实持有者是退出watcher。日志 `/tmp/common-core-repeat-audit/zero-retention-*.log`。

Claude自建隔离Rabbit独立200轮（make、使用/释放channel、close、unset、GC）：

| 探针 | 额外协程 | WeakRef存活 | 内存增量KB |
| --- | ---: | ---: | ---: |
| framework heartbeat=0 | 0 | 0 | 353 |
| package heartbeat=0 | 200 | 200 | 4503 |
| framework heartbeat=3 | 200 | 200 | 4476 |
| package heartbeat=3 | 200 | 200 | 4476 |

因此零心跳是新增累积；正心跳是框架既有同量级行为，本轮不扩大修复。正常池化连接数较少，按P3资源回归记录；总量仍随建连次数增长，不能称固定有界，也不能笼统说非PROCESS完全不变。正规Hyperf Command最终会resume，未证明普通Command挂死，不夸大为ACK/业务正确性故障。建议让零心跳watcher随连接生命周期释放，保留初始化前建连、After兜底、PID范围与原心跳配置。

完整独立补充报告 `/tmp/common-core-repeat-audit/claude-followup.md`。初审另有三条P3文档/日志建议：硬上限到期时延后的退出钩子可能从未执行；被stopStarting拦下的future任务不写框架failed日志；README应用覆盖枚举遗漏ConnectionFactory。均不抵消以上P2。

所有PoC都在临时目录和隔离容器中；人工注入失败/期待137是反例捕获，不能称修复通过。审查方最初timeout4的PID1控制无效，主方仅清理该挂起的审查容器；这个失效超时不作为自动退出码证据，真实Redis对照及随后有效超时数据不受影响。未运行生产凭据/真实业务请求，未修改消费者vendor、部署、提交、tag或发布。当前仍不具备“完全没有问题”的收口条件。


## 用户限定范围后的最终正心跳方案：15/30秒（2026-10-25，未发布）

用户明确：最大协程数至少十万，本轮不扩大前两项创建失败处理；AMQP不允许0，并最终指定默认心跳15秒。两项容量失败缺陷仍是已知未修边界，概率约束不等于代码已修复；包内没有新增max_coroutine配置。此前“阻断”的审查结论对应范围调整前，当前按用户新范围验收，不声称没有任何已知问题。

方案先经Claude审查。最初3秒方案曾完成36项配置检查、17项停止与四项独立复验（`/tmp/common-core-positive-heartbeat`），**但用户随后改为15秒，那些不是本轮15秒证据**。15/30增量批准 `/tmp/common-core-heartbeat15/claude-plan.md`，随后才改默认值和补齐缺失读写超时。

最终Factory仅处理局部配置，再调用parent::make：heartbeat省略或null默认15，显式0/负数/非整数在IO/网络前InvalidArgumentException；已有正整数保留。read_write_timeout省略或null默认30，已有显式值保留，包括测试中的25与0。其他IO、pool、连接超时等均透传；原AmqpDbConfig15/30不变。删除前方案ConnectionExit、ProcessConnectionContext及Provider listener，不再新增逐连接退出watcher，不访问protected退出状态；全部用框架正心跳关闭。Factory原DI标识及应用override/继承继续保留。

新增heartbeat.php共40项无网络配置断言：每个包内/继承Factory各6项默认与参数/调用者不变、10项非法值IO前拒绝、4项最小配置（15/30、params null、显式hb7省略rw为7/30、显式rw0得到15/0）。拒绝0为用户授权的配置不兼容变化，不能静默变成默认值。空AMQP_HEARTBEAT经帮助类int转换也会为0，需删除或改正配置。

主方17项真实隔离回归均exit0；PHP8.4.27/Swoole6.2.3/Hyperf3.2固定fixture，直接只读加载源码，临时Redis/Rabbit和本地HTTP。命令见 `/tmp/common-core-heartbeat15/targeted.log`，产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-w7pv7pdu`，原始HTTP60条与结果合计一致，配置日志为PASS40。源码/测试冻结清单 `/tmp/common-core-heartbeat15/source-manifest.json`，复核哈希一致。

| 用例 | 容器宽限秒 | 实际停止秒 | 退出码 | HTTP数 |
| --- | ---: | ---: | ---: | ---: |
| cron-worker-process | 35 | 19.974 | 0 | 3 |
| cron-both-workers | 35 | 21.952 | 0 | 6 |
| queue-seven | 10 | 9.116 | 0 | 2 |
| queue-pending-start | 15 | 5.175 | 0 | 6 |
| queue-shared-drain | 20 | 10.082 | 0 | 12 |
| amqp-serial | 20 | 12.076 | 0 | 2 |
| amqp-shared | 20 | 13.079 | 0 | 6 |
| amqp-parallel | 30 | 20.044 | 0 | 4 |
| amqp-default-idle | 15 | 9.803 | 0 | 0 |
| amqp-default-int | 15 | 9.756 | 0 | 0 |
| amqp-default-defer | 15 | 10.108 | 0 | 3 |
| amqp-default-shared | 25 | 13.104 | 0 | 6 |
| amqp-default-parallel | 30 | 20.133 | 0 | 4 |
| amqp-worker-heartbeat | 15 | 0.217 | 0 | 0 |
| amqp-default-setup-fault | 15 | 5.206 | 0 | 0 |
| amqp-default-max | 15 | 8.118 | 0 | 3 |
| amqp-positive-heartbeat | 15 | 10.082 | 0 | 3 |

默认idle实际经customIO回传框架原IOFactory，记录heartbeat15/read_write_timeout30/connection_timeout3；其他customIO两连接记录显式25/5未变。默认十二秒并发任务两次HTTP、Memory/短TTL锁续期、停止后AMQP confirm、ACK、直接defer和解锁都完成，broker消费队列ready/unacked=0。worker在退出前取得一次发布确认，再由框架原心跳关闭；不再要求非法零心跳连接跨WORKER_EXIT继续发布。初始化早建连接后异常依靠框架同样正常结束，无需PID兜底类。正数7原样保留，Cron正心跳资源与Redis既有收尾定向回归也通过。

Claude独立四项（每轮先跑40配置检查）：默认idle9.842s、默认并发20.081s、worker0.210s、早建异常5.213s，均exit0；原始事件/IO参数/broker检查一致。产物 `common-core-stop-wjgb2n3e`（父目录同上），日志 `/tmp/common-core-heartbeat15/independent.log`，报告 `/tmp/common-core-heartbeat15/claude-review.md`。独立结论：**在用户本轮15/30范围内没有阻断，可以接受，仍暂停发布。**

PHP语法、Python AST、Factory/ConfigProvider的PHPStan level1和git diff --check通过。Broker不可达/网络分区时的关闭等待未新增故障回归；未配置读写超时现在从框架原3秒变30，阻塞写/失联识别/关闭可能变长，停止宽限应考虑它。默认Hyperf SwooleIO适配15/30；自定义SocketIO要求rw严格大于2倍心跳，15秒需显式大于30；StreamIO至少2倍，正好30可用。Claude先审的SocketIO数学措辞已在独立报告纠正，没有扩大修改用户显式配置。

本次没有重跑无变化的所有45项，更没有把旧45或3秒阶段17项混称当前15秒全量；17项覆盖本次改动直接影响的路径。同步task worker、完整CoroutineServer/WS、未等待后台协程、自定义绕过父Factory及框架正心跳既有闭合连接对象存活行为，仍按既有边界处理。不新增HTTPworker信号、不给signal.timeout默认值、不降业务HTTP/handle_timeout、不清队列或提前ACK。所有测试容器和网络由runner清理。继续不提交/tag/打包/发布/正式Composer安装/消费者升级/业务部署；新代码仍在工作区，基线b22fa78/v3.2.4。


## 当前15/30源码再次全量复验与独立互审（2026-10-25，未发布）

重新核对main/HEAD b22fa78及适用规则，未发现新增适用AGENTS。开始快照 /tmp/common-core-final-audit/start-manifest.json 包含13个原有变更项；完整实测/独立审查期间字节未变，之后仅修改说明和验证记录，不改生产源码、仓库测试逻辑或消费者。用户排除的容量失败仍未修，不因本轮通过而改称已解决。

本次完整执行当前45项（与上轮17项定向不同），先跑40项无网络配置检查。命令 STOP_CHECK_FIXTURE=/tmp/common-core-stop-check python3 tests/process-stop/run.py；日志 /tmp/common-core-final-audit/full.log，产物 /var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-s9gd4r7j，汇总 /tmp/common-core-final-audit/summary.json。PHP8.4.27/Swoole6.2.3/固定Hyperf3.2依赖，source只读直接加载，独立Redis/Rabbit/HTTP，无生产数据。43项正常、两项硬上限；当前caselist含显式正心跳用例，原始HTTP120与逐项和一致，不能混用历史HTTP117数据。

| 用例 | 宽限秒 | 实际停止秒 | 退出码 | HTTP数 | 口径 |
| --- | ---: | ---: | ---: | ---: | --- |
| cron-worker-process | 35 | 19.928 | 0 | 3 | 符合正常收尾断言 |
| cron-both-workers | 35 | 22.043 | 0 | 6 | 符合正常收尾断言 |
| cron-worker-limit | 30 | 4.166 | 0 | 1 | worker内部强制终止，业务未完成 |
| cron-term | 10 | 5.838 | 0 | 0 | 符合正常收尾断言 |
| cron-int | 10 | 5.904 | 0 | 0 | 符合正常收尾断言 |
| queue-idle | 10 | 6.958 | 0 | 0 | 符合正常收尾断言 |
| queue-seven | 10 | 9.050 | 0 | 2 | 符合正常收尾断言 |
| queue-twelve-grace | 30 | 17.228 | 0 | 2 | 符合正常收尾断言 |
| queue-twelve-limit | 10 | 10.141 | 137 | 1 | Docker硬上限，未ACK/reserved=1 |
| queue-pending-start | 15 | 5.096 | 0 | 6 | 符合正常收尾断言 |
| queue-shared-drain | 20 | 10.199 | 0 | 12 | 符合正常收尾断言 |
| amqp-default-idle | 15 | 9.785 | 0 | 0 | 符合正常收尾断言 |
| amqp-default-parallel | 30 | 20.038 | 0 | 4 | 符合正常收尾断言 |
| amqp-worker-heartbeat | 15 | 0.212 | 0 | 0 | 符合正常收尾断言 |
| cron-coroutine-boundary | 45 | 25.151 | 0 | 2 | 符合正常收尾断言 |
| cron-locked-batch | 40 | 28.461 | 0 | 2 | 符合正常收尾断言 |
| cron-custom-boundary | 45 | 25.128 | 0 | 2 | 符合正常收尾断言 |

各项检查两次HTTP、direct-defer、ACK/retry、reserved、锁续期/解锁、Memory、AMQP发布确认和消费队列ready/unacked。两条硬上限不算正常成功；worker内部强制终止仍可主容器exit0。源码/测试及相关模块PHPStan level1、git diff --check通过。没有以composer test代替真实停止，也没有执行正式Composer安装。

Claude新会话0b06455f-a26d-419b-a779-5ac53af3f41e独立六项：default-idle9.763秒、default-parallel20.044秒、worker-heartbeat0.215秒、cron-both-workers21.928秒、pending-start5.102秒、shared-drain10.161秒，均exit0，另检查40配置断言和原始broker/HTTP/锁/defer/PID时序。产物common-core-stop-u1fm6e15（父目录同上），日志 /tmp/common-core-final-audit/claude-tests.log；初审 /tmp/common-core-final-audit/claude-review.md，后续意见 /tmp/common-core-final-audit/claude-followup.md。结论为本用户范围无新增P1/P2，不代表无任何已知问题。

补足真实框架接线：独立方poc/server-di.php使用原Hyperf Server::init，验证两种provider顺序与默认/显式callbacks来源，四组原标识均解析为包内回调/Factory/Executor。主方临时runtime-framework.php调用原defaultCallbacks及registerSwooleEvents，实际原生停止中不再手工on绑定或调用workerExit；先验证应用覆盖再恢复包内绑定。PROCESS十二秒cron19.956秒/0、worker关闭0.212秒/0，资源完整、父事件CID=-1且在直接defer后；产物common-core-stop-xl4hk4iq，日志framework-probe-fixed.log。该增量以宿主机stop时刻夹在任务开始/结束间为条件，native-entry仍由完整45项夹具观察，不混称同一脚本。Claude已核对临时fixture和原始事件。这里验证核心注册及真实退出，不冒充消费者或生产应用启动部署。

验证观察器纠正留档：主方初次探针依赖Swoole Server::getCallback，本镜像启动前返回null，导致临时callable返回类型TypeError、进程未启动。该失败来自夹具，不作为实现缺陷，见framework-debug/probe-error.log；有效探针不依赖getter。独立方第一份额外idle PoC复用容器PID1持久队列，断线后又使用旧channel读取；其新发布消费证据已撤回，不能由旧channel错误推断新连接发布失败。主方45项及独立六项不受影响。

独立方已修正额外PoC：poc2/idle2.php及poc2/results.txt，新内部网络、四独立容器、每组随机唯一队列及payload。ready_before=0，空闲45秒后connected/same_object/channel_open全true；空闲期got=[]，取得publisher confirm且只收到本组唯一payload，ready_after=0。default15/30、null15/30、默认心跳+显式rw20、显式7/14四组均exit0、无error。纠正报告 /tmp/common-core-final-audit/claude-poc-correction.md。原负向rw6只保留6.08秒断开事件，不从旧PoC推断断线后的发布/恢复结果，也不扩大成实现错误。

本轮只修文档：环境变量统一写心跳15秒/读写30秒；tests README删除当前已禁止0却仍保留零心跳支持的一句；说明stopStarting跳过时不打印failed、框架后来关闭则沿用failed日志的排障差异，不修改任务或日志代码。

边界不变：用户按至少十万容量排除的两项创建失败仍未修；框架正心跳close后对象存活未改；显式rw过小会空闲断开，建议配套超时；自定义SocketIO需rw>30；应用自有Factory/回调/Executor、未等待后台协程、同步task worker、完整CoroutineServer/WS未全面覆盖。max_request轮换、退出时broker不可达、退出期间二次信号未新增实测。Swoole max_wait_time与容器宽限独立，不承诺所有进程十秒结束。正式包/Composer/消费者升级未验证，继续不提交、打tag、打包发布、修改vendor或部署。


## 二次信号、轮换和MQ故障：现实范围回归（2026-10-25，未发布）

用户要求补齐三类真实隔离回归，并强调结合实际、不增加复杂机制。生产源码与上轮完整复验相同（前轮start-manifest的6份src哈希全部相等），本轮只新增/调整测试和说明。心跳15/缺失读写30、原信号作用范围、ACK/恢复/重试、锁及业务超时均未改。

最终保留12项，日志 /tmp/common-core-lifecycle/practical-final.log，产物 /var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-41y0tpc_，汇总 /tmp/common-core-lifecycle/practical-summary.json；冻结清单 /tmp/common-core-lifecycle/practical-manifest.json（6份生产源码＋4份测试逻辑）。先跑40项配置检查，12项实际HTTP合计42与原始记录相符，每一项实际outcome严格等于expected_outcome。7项正常、1项错误回收对照、4项故障限制，不能按master exit0或runner断言匹配把限制写成正常关闭。

| 场景 | 停止秒 | 轮换验证秒（若有） | 退出码 | 实际结果 |
| --- | ---: | ---: | ---: | --- |
| queue-repeat-term | 13.154 | — | 0 | 正常收尾 |
| amqp-repeat-int | 13.204 | — | 0 | 正常收尾 |
| amqp-repeat-pid1 | 13.143 | — | 0 | 正常收尾 |
| cron-repeat-term | 15.149 | — | 0 | 正常收尾 |
| cron-max-request-base | 6.915 | 11.190 | 0 | 正常收尾 |
| cron-max-request-process | 6.010 | 8.073 | 0 | 正常收尾 |
| broker-pause-inflight | 19.991 | — | 0 | 正常收尾 |
| broker-paused-close | 27.773 | — | 0 | 框架关闭未完成，有deadlock |
| amqp-repeat-cleanup-pid1 | 27.762 | — | 0 | 框架关闭未完成，有deadlock |
| broker-crash-before-stop | 7.602 | — | 0 | 停止前崩溃，错误回收 |
| broker-network-close | 27.681 | — | 0 | 框架关闭未完成，有deadlock |
| broker-paused-close-tight | 10.199 | — | 137 | 十秒窗口外部强杀，业务已ACK |

二次信号实测：在途的同一PROCESS收到TERM/TERM或TERM/INT后，任务、两次业务HTTP、直接defer的第三次HTTP、锁续期、Memory、AMQP发布确认与ACK均完成；真实观察器顺序分别为[15,15]与[15,2]。容器PID1第二次TERM实测没有被master重复转发到用户进程，业务仍正常；cron退出中的原生worker也未取消旧任务。包内StopHandler实例仍为一份，测试只追加非变更观察器，无WORKER信号。清理阶段复测也向容器PID1发TERM，不用直接强杀内部子PID代表默认停止。

max_request实测：max_request=1、grace=0、reload_async=true，真实 /rotate HTTP200触发，且只收到这一个入站请求。旧任务完整，新PID实际接手后续1秒任务；BASE旧PID7→新47、PROCESS旧8→新49，两轮无重复。表中轮换验证秒指触发请求到旧/新任务均收尾，停止秒则是最后Docker stop的独立阶段，两者不混。夹具只设1worker用于固定PID；不由此推断生产多worker整体可用性，不展开未测的派发窗口。

MQ临时卡住实测：7秒任务启动后暂停临时Rabbit，在冻结期间观察publish-confirm-wait存在、confirmed不存在且Rabbit.State.Paused=true，再解冻。最终确认、ACK、锁和HTTP均完成，broker消费队列ready/unacked=0，没有redelivery、只有一次业务。应用可以在unpause命令返回前收到确认，不能用命令返回时间强比较；当前使用实际阻塞观测。仅此测试的假任务confirm等待放宽到20秒，不改任何生产超时。

MQ故障对照与限制：先完成业务/defer，并用broker实际回读证明ACK0/0，再停止MQ、暂停或断网。broker-crash-before-stop明确是停止前MQ先崩溃：read-error.closing=false、handle已错误返回，exit-coordinator.running=true且早于docker stop；随后本地错误回收，无deadlock。它不是任意时序EOF都能正常握手的证明。持久无响应和网络断开则在约27.7秒后出现Swoole死锁检测，master仍为0；十秒窗口则137。这些均非正常AMQP关闭，io_close_observed只说明本地socket方法被调用，不代表握手成功。业务在故障注入前已ACK，恢复后再回读0/0，无重放；不据此保证永久失联期间未确认的业务也不重投。

实际问题根因已由Claude逐行和真实PoC核对：框架退出时先exited=true，关闭channel默认等RPC回复；读循环超时后退出状态下不再唤醒关闭等待，最终靠Swoole死锁检测或外部停止上限结束。因此rw30不是关闭硬上限。本轮不增加强制断连、改channel RPC等待或新重试机制，按框架现有故障限制记录，不粉饰为包已解决。正常使用、正常broker情况下的重复停止和轮换均通过。

Claude最初独立10项原始资料 /tmp/common-core-lifecycle/independent.log，产物common-core-stop-nki3is6o；其中仍适用的正常信号、轮换、暂停恢复和持续故障结果保留，旧清理后直接杀子PID及rw0探索只作附录，不进入当前默认矩阵。随后独立按当前脚本补三项，日志 /tmp/common-core-lifecycle/independent-practical.log，产物common-core-stop-0ynr8thg：清理阶段PID1重复TERM27.672秒/0/deadlock、MQ停止前崩溃7.623秒/0/错误回收、网络断开27.670秒/0/deadlock。报告 /tmp/common-core-lifecycle/claude-practical.md确认本包没有新增实现缺陷，F1用例口径、F2明确预期断言已解决，真实框架限制不能算正常退出。这几次运行不混称同一快照。

测试开发中两处夹具问题已纠正：解冻返回时刻误判confirm先后；broker重启只等Erlang ping太早，已改为Rabbit应用check_running和实际list_queues就绪。最早探索13＋4项烟测日志final-run.log不是当前12项，不能混入当前统计。收敛后不再扩rw0、低协程上限或直接强杀子PID等额外组合。当前新增默认12项，加旧45共57，可按tests README只跑有关场景；本轮未重新跑不变的全部57。旧4项烟测已完成，生产源码未变。

PHP8.4.27/Swoole6.2.3/固定Hyperf3.2、真实临时Redis/Rabbit和HTTP替身，source只读挂载，没有使用业务配置/数据。PHP语法、Python AST、git diff --check通过。Python不生成pycache，全部测试容器网络清理。已知容量失败等原范围不扩展，正式Composer/消费者升级未验证。继续不提交、tag、打包发布、修改消费者vendor、部署或恢复Rancher Agent。
