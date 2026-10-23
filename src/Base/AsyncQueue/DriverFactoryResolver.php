<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Psr\Container\ContainerInterface;

/**
 * 把框架 Hyperf\AsyncQueue\Driver\DriverFactory 解析为容器内**同一个** BaseDriverFactory 单例。
 *
 * 框架 dispatch()、#[AsyncQueueMessage]、queue:* 命令、ConsumerProcess 都从容器取 DriverFactory;
 * 若直接绑定类名,会得到另一个 BaseDriverFactory 实例,AsyncQueue::push 动态注册的队列在其中不可见。
 * 经此工厂,两套入口共用同一批驱动与同一套 hash tag channel。
 */
class DriverFactoryResolver
{
    public function __invoke(ContainerInterface $container): BaseDriverFactory
    {
        return $container->get(BaseDriverFactory::class);
    }
}
