<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base;

use Dleno\CommonCore\Tools\Check\CheckParams;
use Dleno\CommonCore\Tools\Lock\DcsLock;

/**
 * Class BaseCoreComponent
 * @package Dleno\CommonCore\Base
 */
class BaseCoreComponent
{
    /**
     * 加锁（使用分布式锁时$uuid建议使用雪花算法）
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @param int $time 锁持有时间
     * @param int $timeout 请求锁超时时间(<0:不超时；=0:未获取到锁则直接失败；>0:未获取到锁则抢占式继续获取锁，直到超时)
     * @return bool
     */
    protected function lock(string $lockKey, string $uuid, int $time = 3, int $timeout = 0): bool
    {
        $time = $time <= 0 ? 3 : $time;//不允许持有时间永久，避免极端情况造成死锁
        return DcsLock::lock($lockKey, $uuid, $time, $timeout);
    }

    /**
     * 解锁
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @return bool
     */
    protected function unlock(string $lockKey, string $uuid): bool
    {
        return DcsLock::unlock($lockKey, $uuid);
    }

    /**
     * 执行接口参数校验
     * @param array $rules 规则详见：https://hyperf.wiki/2.0/#/zh-cn/validation
     * @param array $parmas
     * @param array $customAttributes
     * @param array $messages
     */
    protected function checkParams(array $rules, $params, array $customAttributes = [], array $messages = [])
    {
        return CheckParams::check($rules, $params, $customAttributes, $messages);
    }
}
