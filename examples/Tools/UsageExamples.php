<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Tools;

use Dleno\CommonCore\Examples\Amqp\Producer\NormalProducer;
use Dleno\CommonCore\Examples\AsyncQueue\Job\TestJob;
use Dleno\CommonCore\JsonRpc\RpcMqCall;
use Dleno\CommonCore\Tools\AsyncQueue\AsyncQueue;
use Dleno\CommonCore\Tools\Crypt\OpenSslCrypt;
use Dleno\CommonCore\Tools\Crypt\OpenSslRsa;
use Dleno\CommonCore\Tools\Http\HttpClient;
use Dleno\CommonCore\Tools\Lock\DcsLock;

/**
 * 常用工具类示例集合。
 *
 * 本类不会被框架自动调用;所有方法仅供业务复制/显式调用参考。
 */
class UsageExamples
{
    public static function httpGetExample(): array
    {
        return HttpClient::get('https://example.com', ['q' => '0']);
    }

    public static function cryptExample(string $plain, string $key): string|false
    {
        $encrypted = OpenSslCrypt::encrypt($plain, $key);
        if ($encrypted === false) {
            return false;
        }

        return OpenSslCrypt::decrypt($encrypted, $key);
    }

    public static function rsaEncryptExample(string $plain, string $publicKey): string|false
    {
        return OpenSslRsa::encryptedByPublicKey($plain, $publicKey);
    }

    public static function lockExample(string $businessId): bool
    {
        $lockKey = 'example:lock:' . $businessId;
        $uuid    = bin2hex(random_bytes(16));
        if (!DcsLock::lock($lockKey, $uuid, 10, 0)) {
            return false;
        }

        try {
            // 示例:这里放需要互斥执行的业务逻辑。
            return true;
        } finally {
            DcsLock::unlock($lockKey, $uuid);
        }
    }

    public static function asyncQueueExample(array $payload): bool
    {
        return AsyncQueue::push(new TestJob($payload));
    }

    public static function amqpExample(array $payload): bool
    {
        return \Dleno\CommonCore\Tools\Amqp\Producer::send(new NormalProducer($payload), true);
    }

    public static function rpcMqExample(array $payload): bool
    {
        return RpcMqCall::producerRpc(NormalProducer::class, $payload);
    }
}
