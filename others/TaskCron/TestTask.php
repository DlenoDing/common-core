<?php
declare(strict_types=1);

namespace App\TaskCron;

use Hyperf\Task\Annotation\Task;

class TestTask
{
    /**
     * @Task()
     */
    public function handle($cid)
    {
        return [
            'worker.pid' => getmypid(),
            'worker.cid' => $cid,
            // task_enable_coroutine=false 时返回 -1，反之 返回对应的协程 ID
            'task.cid' => \Hyperf\Utils\Coroutine::id(),
        ];
    }

    public function test()
    {
        $container = \Hyperf\Utils\ApplicationContext::getContainer();
        $task = $container->get(\App\TaskCron\TestTask::class);
        $result = $task->handle(\Hyperf\Utils\Coroutine::id());
        var_dump($result);
        var_dump(getmypid());
    }
}
