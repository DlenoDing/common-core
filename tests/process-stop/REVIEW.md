# 退出修复互审记录

本轮修复基于 v3.2.3（c0e4e4759903e897b306b7057575f0bb4d5d5e72）。主方完成源码修改、真实隔离回归和根因对照；独立复验方不修改源码、vendor、其他仓库，不提交或部署。

## Claude 前五轮

用户要求先交 Claude 审修改方式。Claude CLI 会话 `f315c581-35cd-45f3-ac8d-1368b8677808` 完成五轮源码/方案互审，主方对异议逐点复核后采纳：

1. 删除通用信号处理器中的全局清理，只保留 running 标记；调度采用精确策略白名单和独立进程 guard 的 watcher，不复制框架循环或改 getInterval。
2. AMQP Consumer 使用 PriorityDefinition 默认接入；直接把可调用 Factory 类作为另一个 Factory 的别名会造成二次调用，负向 DI 证明后放弃该接法。RedisDriver 用 dependencies 映射，保留应用覆盖。
3. 明确 Concurrent/Parallel 会在业务 defer 之前解除槽位/WaitGroup，不能只等槽位；四条串行/并发路径都要追踪完整回调协程。
4. 定时任务不能在 BeforeExecute 才计数，因为那时已经经过 mutex IO。改用包内 Executor 最外层包装，加锁前计数、整个协程 defer 最后减；调度返回前等待。watcher 同样等本地 Executor，避免先清理后等待。
5. 回调计数按 consume 协程 ID 分桶，保留回调返回值、concurrent=null 的 OR 等待；避免共享 Consumer 实例的快消费者被其他慢消费者拖住。

Claude 第六轮最终复验请求遇到会话额度限制，未执行最终独立重跑；不能表述为 Claude 已终审通过。原始请求/答复在本机 `/tmp/common-core-fix-review/01-plan-response.json` 至 `06-final-review-response.json`。

## 用户授权改用 Codex CLI

用户随后明确选择“暂时切换成codex cli执行”。Codex CLI 独立会话 `01a16f39-275f-73f2-822f-32fd7a13370d` 重新读取规则、完整 diff、新源码、框架消费者/调度器/Coordinator 及测试，独立执行：

- 必需7项：Redis串行/并发 defer、AMQP串行/并发 defer、共享 Consumer、十二秒并发 AMQP、分钟边界带锁批次。
- 额外15项：空闲信号、策略白名单、异常重启、队列资源、reserved 槽位、重试/消息数量上限、真实分钟分发及 Coroutine/custom 跨边界等。
- 为避开审查过程中 Factory 兼容修改的时点，又对稳定文件 SHA-256 清单独立重跑必需7项；全部通过。
- 旧全局提前唤醒负向对照按预期失败，AMQP ACK 报连接关闭；独立检查 broker、Redis、HTTP 请求数与原始事件。
- 独立 Composer 严格校验、PHP语法、PHPStan、反射路径和清理检查通过。

独立原始证据目录 `/tmp/common-core-codex-review.R7GCMi`；首轮报告 `/tmp/common-core-fix-review/codex-final.md`，事件记录 `codex-events.jsonl`。稳定7项的原始用例产物位于 `current-required-7.log` 首行给出的临时目录。独立方明确没有把主方27项全组冒充为自己执行。

首轮结论：无 P1/P2 源码缺陷，源码可接受；P2 文档证据问题是 RESULTS.md 引用的本文件当时不存在，现已补齐。正式发布后的 Composer dist 安装尚需按原发布要求另行验证，不能用本机 checkout 替代。业务应用发布/真实消费者集成不在本轮已验证范围，也不以修改消费者 vendor 或部署来补证据。

## 本方追加的兼容性收尾

全组之后，本方发现 PriorityDefinition 不应绕过应用既有 ConsumerFactory 覆盖，已由包内 factory 委托应用 factory，保留 Consumer key 的覆盖优先级。DI 负向/正向证明在 `/tmp/common-core-fix-review/factory-compat-before.log` 与 `factory-compat-after.log`；默认串行/并发 AMQP 定向复跑通过，证据 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-nfk78h39`。

进一步补足 Container::make 的可选 parameters 转发：原委托只传 container 会丢失应用 factory 的第二参数，修正为可选参数原样透传，并在 runtime 中固化 app factory + make 参数断言。`factory-params-before.log` 为预期参数丢失的反例，`factory-params-after.log` 已确认保留；消费/ACK/计数/调度逻辑未因此改变。

Codex CLI 对参数透传增量及本文件再次独立互审，结论为：**“互审一致，可提交发布。当前没有未解决的 P1/P2 源码阻断项。”**

独立方实际运行两参数 Factory 的 DI 证明，又独立构造旧单参数 Factory 验证兼容；读取参数修正后 AMQP 串行/并发原始日志与 broker 状态（12.175s / 20.085s，均退出0、ready/unacked=0）。确认此两行参数透传不进入 callback/计数/ACK/锁/Timer/调度路径，因此以定向增量验证收口，不形式化重复整组7项。

增量报告 `/tmp/common-core-fix-review/codex-followup-final.md`；独立参数证明 `/tmp/common-core-codex-review.R7GCMi/factory-params-proof.log`、旧单参数兼容 `/tmp/common-core-codex-review.R7GCMi/factory-one-arg.log`；参数修正后的默认 AMQP 产物 `/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-47utpum5`。

主方核对并接受该结论。Claude 只完成前五轮，最终独立复验与一致意见来自用户随后指定的 Codex CLI；两者不混称。源码与测试已收口，正式版本发布/Composer 安装验证按原发布要求执行，消费者业务升级和生产部署不在本次完成声明中。
