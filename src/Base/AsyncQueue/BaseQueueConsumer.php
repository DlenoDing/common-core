<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Process\AbstractProcess;
use Psr\Container\ContainerInterface;

class BaseQueueConsumer extends AbstractProcess
{
    protected string $queue = 'default';

    protected DriverInterface $driver;

    protected array $config;

    protected array $reloadChannel = [];

    protected int $reloadCount = 3;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $driver = get_inject_obj(BaseDriverFactory::class);
        $queue  = $this->getQueue() ?: 'default';
        if (!$driver->has($queue)) {
            $config = $this->getConfig();
            if (is_array($config) && !empty($config)) {
                $driver->set($queue, $config);
            }
        }

        $this->driver = $driver->get($queue);
        $this->config = $driver->getConfig($queue);

        $this->name = "queue.{$this->queue}";
        $this->nums = $this->config['processes'] ?? 1;
    }

    public function handle(): void
    {
        if (!$this->driver instanceof DriverInterface) {
            $logger = $this->container->get(StdoutLoggerInterface::class);
            $logger->critical(
                sprintf(
                    '[CRITICAL] process %s is not work as expected, please check the config in [%s]',
                    BaseQueueConsumer::class,
                    'config/autoload/async_queue.php'
                )
            );
            return;
        }
        //开始reload机制，处理发布时的中断任务
        $this->reloadChannels();

        $this->driver->consume();
    }

    protected function reloadChannels($i = null)
    {
        $i = is_null($i) ? $this->reloadCount : $i;

        $handleTimeout = (intval($this->config['handle_timeout'] ?? 60) + 2) * 1000;
        foreach ($this->reloadChannel as $channel) {
            $this->driver->reload($channel);
        }
        $i--;
        if ($i <= 0) {
            return;
        }
        \Swoole\Timer::after(
            $handleTimeout,
            function () use ($i) {
                $this->reloadChannels($i);
            }
        );
    }

    /**
     * 获取要使用的队列
     * @return string
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * 自定义 async_queue 对应的$this->queue配置项（动态queue时才需要处理此函数）
     * @return array
     */
    public function getConfig()
    {
        return [];
    }

    protected function _getConfig($name = 'default')
    {
        $config = get_inject_obj(ConfigInterface::class)->get('async_queue.' . $name, []);
        if (!empty($config)) {
            //独立队列
            $config['channel'] = $this->getQueue();
        }
        return $config;
    }
}