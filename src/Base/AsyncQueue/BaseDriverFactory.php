<?php
declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\Exception\InvalidDriverException;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\make;

class BaseDriverFactory extends DriverFactory
{
    public function __construct(ContainerInterface $container)
    {
        //父构造按 async_queue 配置「内联」建好各静态队列 driver——但用的是未加 hash tag 的 channel。
        //Redis Cluster 下,RedisDriver::reload() 的 rpoplpush 跨该通道两子键({channel}:failed/timeout → :waiting),
        //通道 5 子键必须同 slot,否则报 CROSSSLOT。父构造不走 set(),无法在那里收口,
        //故这里 parent 之后按相同配置重新 set() 一遍(set 内统一加 tag)覆盖,使静态队列也集群安全。
        //注:set() 的 hashTagChannel 对已带 {} 的幂等,重复执行无副作用;非集群下 {} 仅为普通字符,行为不变。
        parent::__construct($container);
        foreach ($this->configs as $name => $config) {
            if (is_array($config) && isset($config['driver'])) {
                $this->set($name, $config);
            }
        }
    }

    /**
     * 给队列 channel 包 Redis hash tag,使该通道的 5 个子键({channel}:waiting 等)落同一 slot
     * （集群下多键命令 rpoplpush/unlink 不再 CROSSSLOT；非集群下 {} 仅为普通字符，无影响）。
     * 不同 channel(不同队列/不同实例)tag 不同 → 仍按 channel 分片到不同节点。
     * 已带 {} 或空字符串则原样返回(幂等,避免重复包)。
     *
     * 直连 Redis 自行拼这 5 子键的调用方(如 WsKeys::queueSubKeys 给 unlink 用)必须用本方法保持一致,
     * 否则与驱动写入的物理键对不上。
     */
    public static function hashTagChannel(string $channel): string
    {
        if ($channel === '' || strpos($channel, '{') !== false) {
            return $channel;
        }
        return '{' . $channel . '}';
    }

    /**
     * @throws InvalidDriverException when the driver invalid
     */
    public function set(string $name, $config)
    {
        //统一收口:所有驱动注册(静态经构造重注册 / 动态经 AsyncQueue::push、BaseQueueConsumer)
        //都在此把 channel 包上 hash tag,业务无需感知即获得集群安全。
        if (is_array($config) && isset($config['channel']) && is_string($config['channel'])) {
            $config['channel'] = self::hashTagChannel($config['channel']);
        }

        $driverClass = $config['driver'];

        if (! class_exists($driverClass)) {
            throw new InvalidDriverException(sprintf('[Error] class %s is invalid.', $driverClass));
        }

        $driver = make($driverClass, ['config' => $config]);
        if (! $driver instanceof DriverInterface) {
            throw new InvalidDriverException(sprintf('[Error] class %s is not instanceof %s.', $driverClass, DriverInterface::class));
        }
        $this->configs[$name] = $config;
        $this->drivers[$name] = $driver;
    }

    public function has(string $name)
    {
        if (isset($this->drivers[$name]) && isset($this->configs[$name])) {
            return true;
        }
        return false;
    }

}
