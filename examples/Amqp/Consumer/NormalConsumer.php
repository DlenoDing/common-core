<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Amqp\Consumer;

use Dleno\CommonCore\Base\Amqp\BaseConsumer;
use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Result;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * 【普通调用】AMQP 普通（直连）消费者示例，与 {@see \Dleno\CommonCore\Examples\Amqp\Producer\NormalProducer} 配对。
 *
 * examples 目录不在 composer autoload / 注解扫描范围内；isEnable() 默认依赖 COMMON_CORE_EXAMPLE_ENABLE=false，
 * 即使误扫到也不会启动真实消费。复制到业务项目后把 namespace 改为 App\Amqp\Consumer。
 */
#[Consumer(exchange: 'CommonCoreExampleNormalExchange', routingKey: 'CommonCoreExampleNormalRouting', queue: 'CommonCoreExampleNormalQueue', name: 'CommonCoreExampleNormalConsumer', nums: 1)]
class NormalConsumer extends BaseConsumer
{
    protected string $poolName = 'consumer';

    protected string $exchange = 'CommonCoreExampleNormalExchange';

    protected array|string $routingKey = 'CommonCoreExampleNormalRouting';

    protected ?string $queue = 'CommonCoreExampleNormalQueue';

    public function consume($data): Result
    {
        if (!parent::checkRunning()) {
            return Result::REQUEUE;
        }

        // 示例：在这里处理 $data。
        return Result::ACK;
    }

    public function isEnable(): bool
    {
        if (!env('COMMON_CORE_EXAMPLE_ENABLE', false) || !env('AMQP_ENABLE', false)) {
            return false;
        }
        return config('app_env') !== 'local';
    }
}
