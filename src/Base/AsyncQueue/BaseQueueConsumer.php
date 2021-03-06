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
    /**
     * @var string
     */
    protected $queue = null;

    /**
     * @var DriverInterface
     */
    protected $driver;

    /**
     * @var array
     */
    protected $config;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $driver = get_inject_obj(BaseDriverFactory::class);
        $queue = $this->getQueue() ?: 'default';
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
        if (! $this->driver instanceof DriverInterface) {
            $logger = $this->container->get(StdoutLoggerInterface::class);
            $logger->critical(sprintf('[CRITICAL] process %s is not work as expected, please check the config in [%s]', BaseQueueConsumer::class, 'config/autoload/queue.php'));
            return;
        }

        $this->driver->consume();
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