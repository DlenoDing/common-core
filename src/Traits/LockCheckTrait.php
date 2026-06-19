<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Traits;

use Dleno\CommonCore\Tools\Check\CheckParams;
use Dleno\CommonCore\Tools\Lock\DcsLock;

/**
 * 分布式锁 + 参数校验的公共方法（BaseCoreController / BaseCoreComponent 共用，消除重复）
 */
trait LockCheckTrait
{
    /**
     * 加锁（使用分布式锁时 $uuid 建议使用雪花算法）
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @param int $time 锁持有时间
     * @param int $timeout 请求锁超时时间(<0:不超时；=0:未获取到锁则直接失败；>0:抢占式继续获取，直到超时)
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
     * 含 request 的类（如 Controller）：$params 为空时默认取 post 参数；
     * 无 request 的类（如 Component）：需显式传入 $params。
     * @param array $rules 规则详见：https://hyperf.wiki/2.0/#/zh-cn/validation
     * @param array $params
     * @param array $customAttributes
     * @param array $messages
     */
    protected function checkParams(array $rules, $params = [], array $customAttributes = [], array $messages = [])
    {
        if (empty($params) && isset($this->request)) {
            $params = $this->request->post();
        }
        return CheckParams::check($rules, $params, $customAttributes, $messages);
    }
}
