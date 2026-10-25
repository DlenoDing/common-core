# 退出修复互审记录

> 发布执行（2026-10-25）：用户已明确授权打包发布，已核对远端及Packagist最新v3.2.4，选择未占用v3.2.5。此前“暂停发布”均为历史阶段；本次不升级消费者、不修改其vendor或部署业务。最终发布与正常Composer安装结果另行交付。

> 最新实际场景复验（2026-10-25）：二次信号、真实max_request轮换、MQ故障回归已补齐。最终12项为7项正常收尾、1项停止前MQ崩溃的错误回收对照、4项明确故障限制，不是12项都正常关闭。生产源码未改，只补测试和说明；不再扩展极端参数或纯理论组合。继续不提交、不打包发布。

> 最新全量复验（2026-10-25）：当前15/30源码重新通过40项配置检查和45项停止回归（43正常、两项硬上限、HTTP120）；Claude独立六项及补充验证通过，本用户范围内未发现新增P1/P2。只修订说明与验证记录，生产源码/仓库测试逻辑未改。已知容量失败和框架资源行为仍按原边界保留，继续不提交、不打包发布。

> 最新用户范围（2026-10-25）：用户提供最大协程数至少十万的运行约束，两项创建失败问题本轮不扩展，仍是已知未修边界。AMQP按用户最新要求禁止0、心跳缺失默认15秒、读写超时缺失默认30秒，旧零心跳watcher/PID方案已撤除；以下零心跳实现与反例为历史记录。15/30秒正心跳新回归及独立互审已通过，最新增量见文末，继续暂停提交、打包和发布。

> 再次完整复验（2026-10-25）：新增反例确认两条 P2（串行 pending 泄漏、并发槽位泄漏后无限等待）及一项 P3（零心跳 watcher 保留已关闭连接）。当前未发布工作区仍需修复；此前“无未解决 P1/P2”结论仅对应旧覆盖范围，已被本轮更新。继续暂停提交、打包和发布。

> 上一轮增量（已由本轮更新）：已按用户授权完成已有 cron 延后清理及零心跳 PROCESS 修复；完整45项（43项正常、两项硬上限边界）和双 worker 夹具稳定性增量已完成，Claude 最终互审已一致，范围内未发现未解决 P1/P2。仍暂停提交、打包和发布。
> 前轮范围说明（本段为授权前历史记录）：后续 Claude 已确认退出日志中的 LOCK_EX 会污染跨 worker HTTP/137 反例，这部分证据撤回；资源提前清理仍有效。零心跳独立 PROCESS 修复与新验证另见文末。跨 worker cron 的退出约束尚未获准放宽，不能声称两项 P2 均已完成。

> 以下原有记录对应已发布 v3.2.4（b22fa78）。后续审计确认了 Factory 自委托及 Redis 共享 consume 收尾两项 P2；本轮增量证据另见文末。此前“可发布”结论不能用于本轮未发布修改。

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


## 两项 P2 的后续互审（2026-10-25，未发布）

后续根源审计推翻了此前“已无 P2”的范围判断：合法应用继承包内 Factory 可自委托递归；共享 RedisDriver 的全局 Concurrent 等待使一个 consume 的自然收尾被其他 consume 阻塞。两项均有真实旧版反例，不以历史发布通过替代当前验证。

修正只涉及 Factory 对象身份判断和 Redis prepared/running 的 consume owner 计数。未改消费/ACK/retry/恢复/锁/调度实现。主方新增 Factory 继承/parent/参数断言和四项真实隔离用例，完整重跑 31 项；独立 Codex CLI 会话 `01a16f69-e02d-7cf2-9359-7e3307555f30` 深读框架调用链，独立执行九项，另检查原始 HTTP、ACK/retry/defer/WORKER_EXIT 时序与 broker，而非仅采信 PASS。

首轮报告 `/tmp/common-core-p2-review/review.md`：默认生产路径无未解决 P1/P2，两项 P2 最小修复成立；保留自定义 callback 装饰器调用前异常/丢弃父闭包的计数泄漏风险，以及协程创建失败的框架既有边界。主方接受这些边界并在 README 明确，未引入取消/恢复机制。独立方指出 RESULTS/REVIEW 顶部指向缺少的本轮文末证据，现已补齐；完整数值与产物见 RESULTS 增量段。

嵌套 parallel/defer 独立复核与主方一致：只保证直接任务协程，不递归追踪未等待子协程；unjoined 是边界观察，barrier 是显式等待的正向验证，不能混称两个都自动受保护。

用户已明确暂停发布：本轮即使互审通过，也仅保留本地修改与证据，不执行打包、提交、tag、push 或发布。当前仍是本地未提交修改，不提供本轮正式版本/Composer 成功声明。

最终增量复验已完成，报告 `/tmp/common-core-p2-review/final.md`。Codex CLI 逐字段核对完整 31 项与独立九项、重算 85 条 HTTP 原始日志、检查 broker/锁/ACK/defer 时序，另独立重跑两处源码 PHPStan level 1。结论：**“互审一致：当前本地修改可以保留。”** 未发现未解决 P1/P2 或实质性文档错误；此前缺失增量文末证据已补齐。主方接受此结论，已核对源码/测试哈希不变、测试容器及网络清理完成。保留上述未验证边界，继续暂停打包和发布。


## Claude 方案先审、实现与复验（2026-10-25，未发布）

本轮首先交 Claude 审可行方案。补充会话 c7251c27-a8b5-40d0-9d17-1efc27aed25d 在写源码前认可 B2：父工厂保留构造/参数，子类静态访问器沿用框架 exited=true → close 关闭序列。随后主方提出仅对 PROCESS PID 生效，Claude 审查同意并补充 Before/After 事件双标记防止初始化异常漏记，才落地最终范围。批准文件 `/tmp/common-core-p2-rootfix/claude-supplement.md`、`claude-scope.md`。

初始父会话曾误认为新代码出现时互审未完成；其后已读取上述并行补充会话证据，在 `/tmp/common-core-p2-fix-review.md` 末尾撤回该流程质疑。先审后改的批准链以实际事件文件和上述明确同意为准，不能把某一个并行会话尚未结束误作所有审查均未完成。

实现只补零心跳连接清理和 PROCESS 范围，不改应用 heartbeat、不复制框架工厂构造、不替换连接实例、不反射、不增加 worker 信号或退出回调。HTTP worker 零心跳保持既有行为，独立兼容性测试在 WORKER_EXIT 后仍能两次取得 publisher confirm。普通应用 dependencies 覆盖优先，继承包工厂保留该能力。

主方九项定向、完整四十项均满足各自断言。父会话 c20f15e8-4d86-4e71-b273-bec83cd147ff 再用当前仓库源码独立运行 shared/parallel/worker-publisher/setup-fault 四项，逐个核对真实 broker、共享返回顺序、PID 与异常收尾，无阻断源码/测试问题，明确：**“AMQP 部分（P2-B）互审一致：可接受。”** 报告 `/tmp/common-core-p2-rootfix/final-independent-review.md`。其文档非阻断意见已处理：主 README 首句明确 PROCESS/正心跳范围，测试说明写明升级 Hyperf 后重跑零心跳套件。

本轮还确认并修正测试观察器 LOCK_EX 死锁。旧的第二 HTTP/137 反例撤回；无锁重跑仍证明 Memory/锁在业务结束前被清理。OnWorkerExit 非协程监听器无法合法等待，真正保护接收 worker 需延后框架退出回调。用户原约束禁止该动作，已请求明确有限例外，当前不改 cron 源码，不把文档声明当修复，不宣称两项都完成。应用 max_wait_time 与容器宽限须分别配置，包未设统一硬上限。

保留全部原有及本轮本地修改，无新提交/正式版本/Git tag/发布，无消费者 vendor 修改或业务部署。完整数值和原始产物见 RESULTS 最新增量段；正式 Composer 验证未执行，不能以本地 fixture 代替。

完整四十项结束后，另补强测试配置接线：由真实 ConfigProvider.listeners 经 ListenerProviderFactory 注册，而非手动硬塞 Context 类。生产源码未变，四个代表用例重新通过；主方最终文档已区分完整四十项和此测试接线增量，未把不同脚本快照混称为同一次运行。

最后一次 Claude 对齐已确认：真实 Provider 接线无重复信号或 Context 漏接，四个增量用例与原始 broker/After PID 字段一致，AMQP 部分最终无阻断问题；结论 `/tmp/common-core-p2-rootfix/claude-final-consensus.md`，已追加至独立报告。cron 的源码、信号和策略均未擅自改变，仍等用户对有限例外的明确选择。

## 已授权的 cron 接收 worker 收尾（2026-10-25，未发布）

用户明确允许仅为已有 cron 延后框架退出清理。主方先送具体方案，再与 Claude 核对 Swoole 6.2.3 源码：撤回 Event::defer 为原生上下文的假设，选用原生 tick 保活，父回调保留 CID=-1 和框架重复事件语义。Claude 独立 BASE/PROCESS PoC 后在 `/tmp/common-core-cron-exit-fix/claude-plan-final.md` 明确 approve，之后才修改三处生产源码：WorkerExitCallback 新子类、Executor stopStarting、ConfigProvider 原标识普通映射。零心跳 PROCESS、Factory 参数/继承及 Redis consume 隔离原修改全部保留。

Claude 独立实际运行 PROCESS 十二秒 cron、TaskWorkerStrategy、零心跳共享 AMQP 三项，又独立运行 ProcessStrategy 双 worker 与 Swoole 硬上限两项；原始 broker、HTTP、资源、回调 PID/CID/顺序均核对，源码无阻断。报告 `/tmp/common-core-cron-exit-fix/claude-implementation-review.md`、`claude-boundaries.md`。同步 task worker、应用自定义 callback/Executor、完整 CoroutineServer/WS、未等待后台协程、保活创建失败仍是明确边界，不宣称所有进程十秒结束。

主方完整45项：43项正常、两项硬上限限制，117条HTTP原始记录与逐项总和一致。Swoole 两秒负例证明主容器可 exit0 而 worker 已强制终止，不能冒充正常退出。完整运行产物 `common-core-stop-ve58eiv6`；十个源码/脚本哈希在 `/tmp/common-core-cron-exit-fix/source-manifest-full45.json`。Claude 指出的双 worker 夹具时序余量已增大（future从10秒改20秒），生产源码未变，仅受影响用例定向复跑22.101秒、exit0、两任务六HTTP；产物 `common-core-stop-s1xxzxc1`，最终哈希 `/tmp/common-core-cron-exit-fix/source-manifest-final.json`。两次测试快照分别记录，不混称一次全组。

源码及定向回归完成后，已交最终文档与证据对齐互审（结论见下段）。无新提交、tag、正式版本、正常Composer安装或部署声明，继续按用户要求暂停打包发布。

最终 Claude 已完成数据/哈希/源码/文档对齐，报告 `/tmp/common-core-cron-exit-fix/claude-final-consensus.md`：**“没有未解决的 P1/P2，也没有重大验证缺口。互审一致：当前本地未发布修改可接受。”** 主方核对并接受。唯一非阻断建议已采纳：主 README 补写本次验证的 Swoole6.2.3 未配置 max_wait_time 时底层默认三秒，应用已有设置优先；源码宏 SW_WORKER_MAX_WAIT_TIME=3 及 Server 默认成员已核对，没有修改任何运行参数。源代码与最终哈希保持一致，测试容器及网络已清理。正式发布/正常Composer安装与消费者清理仍待用户后续决定，不把本轮隔离测试当作正式版本可用证明。


## 再次对抗性复验：撤回此前放行结论（2026-10-25）

新会话619396d1-40eb-43bc-ab67-5f62db439ad1先独立跑8项并给出常规路径无新P1/P2；主方新增容量失败和连接存活对照后，Claude用自己编写的framework/HEAD/worktree × serial/concurrent探针、AMQP并发探针、自建Rabbit200轮四组心跳对照，以及独立真实Redis重跑，明确撤回首轮结论。

双方一致确认：串行pending计数在创建失败后永久泄漏是本地新P2；Redis/AMQP并发失败槽位导致包内无限等待是HEAD已有P2；非PROCESS零心跳watcher保留关闭连接是真实新增P3资源回归。B按正常池化场景、既有正心跳对照定P3，不等于它不存在；总量随连接重建增长。完整报告 `/tmp/common-core-repeat-audit/claude-followup.md`，精确数值及原始证据见RESULTS本轮文末。

本轮原有45项全量重新跑过（43正常、两项既有硬限、HTTP117），仍不能覆盖或否定新增反例。当前工作区在修复P2之前不可接受。修复应补创建失败/被丢弃回调的收尾释放和槽位回滚，保留真实pending、running和直接defer；不能清空计数、提前ACK、强改心跳或恢复统一五秒/十秒硬上限。需要覆盖{串行,并发}×{信号停止,max_messages自然退出}及AMQP调度失败，并重新核对早建连接与应用覆盖。

主方未实施源码修复，仅记录本轮发现并更新README待修提示。所有生产源码/测试与本轮开始哈希一致；保留已有其他修改。继续不提交、打包、tag、push、正式安装或部署，消费者文件/vendor不动。之前“无未解决P1/P2”是被本次反例推翻的历史结论，不作为当前发布依据。


## 用户最新范围收口：正心跳15、读写默认30（2026-10-25）

用户基于最大协程数至少十万的运行约束，明确本轮不扩展两个创建失败分支；它们仍未修复，不能把范围调整描述为代码缺陷消失。用户要求禁止0心跳，随后明确默认15。先审批准 `/tmp/common-core-heartbeat15/claude-plan.md` 后，Factory改为正整数校验、缺失15、缺失rw30并调用parent；显式值及其他参数保留。旧逐连接watcher、ConnectionExit、PID标记及listener全部删除，应用覆盖优先和Factory继承保留；Cron获准的延后清理保留。

主方40配置断言＋17项真实隔离回归（exit0、HTTP60）已通过；Claude独立四项和40配置检查也通过，核对真实IO参数15/30/3、显式25/5、HTTP/锁/ACK/defer/broker/worker退出时序。独立报告 `/tmp/common-core-heartbeat15/claude-review.md` 明确“在用户本轮范围内没有阻断，当前15/30实现可以接受”。源码/测试哈希清单 `/tmp/common-core-heartbeat15/source-manifest.json`；详细逐项耗时与原始产物见RESULTS最新增量。3秒阶段及更早零心跳/PID方案只作为历史，不混入本轮数据。

默认15/30只对包内Factory或调用parent::make的继承实现生效；应用自有Factory可绕过，责任由应用保留。自定义SocketIO需rw大于30；该要求的先审数学错误在独立报告纠正，当前源码/README准确。不宣称框架正心跳close后无对象存活，不宣称Broker不可达也能十秒结束。配置0改为报错属于用户明确授权的兼容性变化，发布前应核对应用配置；当前用户仍暂停发布，未执行新提交、tag、push、正式Composer安装或部署，未修改消费者vendor。

最终证据对齐已完成，报告 `/tmp/common-core-heartbeat15/claude-final.md`：13个源码/测试哈希一致、17项/HTTP60、独立4项及40配置检查与文档逐项相符；双方在用户本轮15/30范围内互审一致，无新增阻断。保留两项协程创建失败未修边界和框架正心跳既有资源行为，不以“完全无已知问题”收口。测试容器/网络已清理，继续暂停提交、标签、打包发布与消费者升级。


## 当前15/30版本再次完整复验（2026-10-25）

冻结13个原变更项后重新执行45项及40配置检查，43正常、两项既有硬限，HTTP120与原始合计一致；产物common-core-stop-s9gd4r7j，日志 /tmp/common-core-final-audit/full.log。不可混称旧117数据或上轮17项，生产源码及仓库测试逻辑未改。

新会话0b06455f-a26d-419b-a779-5ac53af3f41e独立六项、40配置检查、真实Hyperf Server.init四组DI接线及修正后的45秒唯一消息PoC四组，未发现本用户范围内新增P1/P2。主方原框架注册方法与实际PROCESS cron/worker退出增量19.956/0、0.212/0均通过；独立方已核对临时fixture及原始时序。报告 /tmp/common-core-final-audit/claude-review.md、claude-followup.md、claude-poc-correction.md；精确数字及各自快照见RESULTS最新增量，不混称同次运行。

主方审出两项观察器问题并纠正：启动前getter返回null导致临时TypeError，撤回该夹具失败；独立额外PoC复用队列/读取旧channel的投递证明，改为唯一队列/唯一payload/confirm/ready回读后重测，不以错误观察器作通过或失败依据。正常45项及独立六项不受影响。

本轮仅修两处文档P4并澄清cron跳过日志差异。已知容量失败未修，用户范围排除不等于代码修复；框架原资源行为和其他边界不变。不宣称完全无已知问题，不宣称消费者已生效或正式Composer可安装。继续暂停提交、标签、打包、发布及部署，保留其他修改。

本轮最终对齐报告 /tmp/common-core-final-audit/claude-final.md 已确认各次运行与修正观察器的证据口径一致、当前用户范围内无新增阻断。主方接受该结论。冻结变更清单为6份生产源码、3份测试逻辑、4份文档；最终仅4份文档不同，其余字节一致。所有测试容器/网络已清理，继续暂停提交和发布。


## 现实生命周期场景独立互审（2026-10-25）

本轮按用户要求补二次停止、max_request、MQ不可达，并按用户后续指示收敛到真实操作，不扩极端参数和纯推演。生产源码未改；新增lifecycle_cases.py及runtime/run的test-only触发和观察。最终12项：7正常、1停止前MQ崩溃的错误回收对照、4明确关闭限制，40配置检查、HTTP42。产物与逐项数据见RESULTS本轮文末，冻结清单 /tmp/common-core-lifecycle/practical-manifest.json。

Claude先审真实方案并独立复现框架关闭等待，初次独立10项后指出两处测试质量问题：F1错误回收不能冒充停止中EOF握手，F2不同边界不能无预期断言地漂移成PASS。已分别改名/等待真实错误路径并断言running=true早于stop，以及固定expected_outcome、未知错误直接fail。用户收敛后移出rw0和清理后直接杀子PID，改为真实向容器PID1重复TERM。默认新12项已完整实跑，Claude再独立三项确认当前口径和断言，报告 /tmp/common-core-lifecycle/claude-practical.md（旧完整报告claude-review.md保留为阶段记录）。

现实结论一致：在途信号、实际worker轮换及MQ暂时卡住恢复后，工作和确认完整，没有新增包内实现缺陷；MQ持续不响应/断网时框架关闭存在真实限制，deadlock横幅即使master0也不是正常关闭，短窗口137也仅外部兜底。任务在永久故障前已ACK，不能推导永久失联期间也保证确认。当前没有为规避故障增加生产强关、重试、HTTPworker信号或其他复杂机制。

本轮仅测试及说明变动，源6份与前轮哈希一致。按实际结果收口，不以理论条件增加实现范围；既有未处理边界保留，不声称完全无已知问题。继续暂停提交、标签、打包发布和业务部署，消费者vendor不动。

最终文字与证据对齐已完成，报告 /tmp/common-core-lifecycle/claude-final.md：12项/HTTP42、严格expected_outcome、独立三项与说明一致，10个源/测试哈希一致。主方接受该结论。现实正常场景无新增包内实现错误；持续MQ不可达的框架关闭限制明确保留，不当正常退出，也不扩大已确认数据的结论。测试容器/网络清理完成，继续不提交和发布。
