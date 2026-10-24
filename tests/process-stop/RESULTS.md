# 进程停止验证记录

日期：2026-10-25（Asia/Kuala_Lumpur）。基线：`5da2015bc88f711ed652abaa09623563371679aa`。

运行环境：PHP 8.4.27 / Swoole 6.2.3；Hyperf process 3.2.0、crontab 3.2.0、async-queue 3.2.2、signal 3.2.0、coordinator 3.2.1.1；临时 Redis 7.4 与 Swoole HTTP 替身。

最终命令：`STOP_CHECK_FIXTURE=/tmp/common-core-stop-check python3 tests/process-stop/run.py`。该 fixture 由包自身 composer.json 正常解析生成，不来自消费者应用。

| 用例 | 宽限秒 | 实际停止秒 | 退出码 | reserved | HTTP 次数 |
| --- | ---: | ---: | ---: | ---: | ---: |
| cron-term | 10 | 5.147 | 0 | 0 | 0 |
| cron-int | 10 | 5.166 | 0 | 0 | 0 |
| queue-idle | 10 | 6.796 | 0 | 0 | 0 |
| queue-seven | 10 | 7.110 | 0 | 0 | 2 |
| queue-twelve-grace | 30 | 12.086 | 0 | 0 | 2 |
| queue-twelve-limit | 10 | 10.110 | 137 | 1 | 1 |

TERM 调度用例距下一分钟约 44.34 秒；INT 用例直接向 PROCESS 发送 SIGINT，再以 TERM 关闭服务器，未将主进程 SIGINT 行为冒充包内处理器行为。两个用例均确认 running=false、协调器唤醒、未分发下一分钟任务。

七秒和十二秒（三十秒宽限）用例：慢 HTTP 开始后才停止；两次请求均成功，任务仅执行一次，正常 ACK 一次、reserved=0；HTTP 池维护已结束，后续请求新建连接成功。十二秒（十秒硬上限）用例：预期强杀、未 ACK、reserved=1，只收到第一个 HTTP 请求；此项为边界复现，不是正常退出通过。

ConfigProvider 开关、原注册标识、容器解析包内子类、应用显式覆盖、停止信号单次注册和 PROCESS 范围、WS 消费者继承的管道超时均通过定向断言。没有复制 bindServer 或调度循环。

另通过 composer validate --strict、包内 composer test（语法检查）、定向 PHP 语法检查、PHPStan level 1 和 git diff --check。语法检查不作为停止行为证据。

原始 JSONL/日志和 results.json（本机临时产物）：`/var/folders/5y/sjklwp1132s5_rfqpfhcjdwc0000gn/T/common-core-stop-p79906y7`。测试运行容器和网络已清理。

WORKER_EXIT 审核：包内 HTTP 池关闭空闲连接，忙碌连接在 finally 中请求完成后关闭；池关闭后仍允许当前业务后续新请求。框架 Crontab 分钟等待及 Redis 分布式锁清理协程响应协调器；AbstractProcess 保持原退出流程。WS 注册进程仍 sleep(15) 后检查 running，未缩短；WS 两类队列继承 BaseQueueConsumer。AMQP BaseConsumer::checkRunning/框架 Consumer 依然检查 running，但框架 AMQPConnection 心跳协程在协调器唤醒后关闭连接，不能据此承诺在途 AMQP ACK。未执行 RabbitMQ/AMQP 在途、真实 WS 业务、自定义进程或协程服务器测试；也未验证所有依赖组件的每一种协调器消费者。

正式包 Composer 安装为发布后单独验证，不能用此处 checkout 挂载替代。
