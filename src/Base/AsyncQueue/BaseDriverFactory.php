<?php
declare(strict_types=1);

namespace Dleno\CommonCore\Base\AsyncQueue;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\Exception\InvalidDriverException;

class BaseDriverFactory extends DriverFactory
{
    /**
     * @throws InvalidDriverException when the driver invalid
     */
    public function set(string $name, $config)
    {
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
