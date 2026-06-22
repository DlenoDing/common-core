<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\TaskCron;

use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Coroutine;

/**
 * Task 用法示例。
 *
 * hyperf-diy 当前没有启用 Hyperf Task 注解示例,这里保留为参考代码:
 * - 不声明真实 #[Task] 属性,避免业务未安装/未启用 task 组件时扫描出错。
 * - 如业务确实使用 task worker,复制到 app 后按项目依赖自行加上 #[\Hyperf\Task\Annotation\Task]。
 */
class TestTask
{
    public function handle(int $cid): array
    {
        return [
            'worker.pid' => getmypid(),
            'worker.cid' => $cid,
            'task.cid'   => Coroutine::id(),
        ];
    }

    /**
     * 调用示例。不会被框架自动执行。
     */
    public static function callExample(): array
    {
        $container = ApplicationContext::getContainer();
        $task      = $container->get(self::class);

        return $task->handle(Coroutine::id());
    }
}
