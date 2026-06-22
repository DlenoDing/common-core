<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Http\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Examples\Http\Component\Object\TestObject;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

class TestComponent extends BaseCoreComponent
{
    private const CACHE_DATA_KEY = 'example:test:data:';

    private static int $cacheTimeout = 30;

    private static array $cacheData = [];

    private static array $cacheTimes = [];

    #[Inject]
    protected Redis $redis;

    public function getData(string $key): TestObject
    {
        if (isset(self::$cacheData[$key]) && self::$cacheTimes[$key] <= time()) {
            unset(self::$cacheData[$key], self::$cacheTimes[$key]);
        }
        if (!isset(self::$cacheData[$key])) {
            self::$cacheData[$key]  = $this->getCacheData($key);
            self::$cacheTimes[$key] = time() + self::$cacheTimeout;
        }

        return clone self::$cacheData[$key];
    }

    private function getCacheData(string $key): TestObject
    {
        $data = $this->redis->hGetAll(self::CACHE_DATA_KEY . $key) ?: [];

        return (new TestObject())->fill($data);
    }
}
