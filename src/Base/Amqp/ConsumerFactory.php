<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base\Amqp;

use Hyperf\Amqp\ConnectionFactory;
use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Container\ContainerInterface;

class ConsumerFactory
{
    public function __invoke(ContainerInterface $container, array $parameters = []): \Hyperf\Amqp\Consumer
    {
        // Preserve an application's existing factory override as well as its Consumer binding.
        $factory = $container->get(\Hyperf\Amqp\ConsumerFactory::class);
        if ($factory !== $this && $factory::class !== \Hyperf\Amqp\ConsumerFactory::class) {
            return $factory($container, $parameters);
        }

        return new Consumer(
            $container,
            $container->get(ConnectionFactory::class),
            $container->get(StdoutLoggerInterface::class)
        );
    }
}
