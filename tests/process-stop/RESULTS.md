# 通用进程退出修复回归记录

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
