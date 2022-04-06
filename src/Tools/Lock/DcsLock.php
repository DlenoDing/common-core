<?php

namespace Dleno\CommonCore\Tools\Lock;

use Dleno\CommonCore\Tools\Logger;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hyperf\Snowflake\IdGeneratorInterface;
use Hyperf\Context\Context;

class DcsLock
{
    /**
     * 加锁（使用分布式锁时$uuid建议使用雪花算法）
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @param int $time 锁持有时间
     * @param int $timeout 请求锁超时时间(<0:不超时；=0:未获取到锁则直接失败；>0:未获取到锁则抢占式继续获取锁，直到超时)
     * @return bool
     */
    public static function lock(string $lockKey, string $uuid, int $time = 3, int $timeout = 0): bool
    {
        $time = $time <= 0 ? 3 : $time;//不允许持有时间永久，避免极端情况造成死锁
        //有超时设置的适用于秒杀抢购等队列场景
        //无超时设置，则直接失败，适用于类似缓存击穿场景
        //匿名函数调用，避免递归造成内存溢出（递归过程中的内存始终无法释放）
        $result = self::closureLock($lockKey, $uuid, $time, $timeout * 1000);
        while ($result instanceof \Closure) {
            $result = $result();
        }
        if ($result) {
            defer(function () use ($lockKey, $uuid){
                self::unlock($lockKey, $uuid);
            });
        }
        return $result;
    }

    /**
     * 加锁（使用分布式锁时$uuid建议使用雪花算法）
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @param int $time 锁持有时间
     * @param int $timeout 请求锁超时时间；毫秒(<0:不超时；=0:未获取到锁则直接失败；>0:未获取到锁则抢占式继续获取锁，直到超时)
     * @return bool|\Closure
     */
    private static function closureLock(string $lockKey, string $uuid, int $time = 3, int $timeout = 0)
    {
        $redis = get_inject_obj(RedisFactory::class)->get(config('app.dcslock_redis_pool', 'default'));
        if ($redis->set($lockKey, $uuid, ['NX', 'EX' => $time])) {
            return true;
        }
        if ($timeout === 0) {
            return false;
        }
        $wait = $timeout;
        if ($timeout > 0 && ($ttl = $redis->ttl($lockKey) * 1000) > 0 && $ttl < $timeout) {
            $wait = $ttl;
        }
        $popTime = microtime(true);
        $wait    = intval($wait > 1000 ? round($wait / 1000) : 1);
        $pop     = $redis->blPop($lockKey . '_WAIT', $wait);
        $wait    = $wait * 1000;
        if ($pop) {
            $popTime = intval((microtime(true) - $popTime) * 1000);
            if ($timeout > 0) {
                $timeout = $timeout - $popTime;
                $timeout = $timeout > 0 ? $timeout : 0;
            }
            unset($pop, $wait, $ttl, $popTime);
            return function () use ($lockKey, $uuid, $time, $timeout) {
                return self::closureLock($lockKey, $uuid, $time, $timeout);
            };
        }
        if ($timeout < 0) {
            unset($pop, $wait, $ttl, $popTime);
            //使用了匿名函数，则不需要休眠等待
            return function () use ($lockKey, $uuid, $time, $timeout) {
                return self::closureLock($lockKey, $uuid, $time, $timeout);
            };
        }
        if ($wait < $timeout) {
            $timeout = $timeout - $wait;
            unset($pop, $wait, $ttl, $popTime);
            return function () use ($lockKey, $uuid, $time, $timeout) {
                return self::closureLock($lockKey, $uuid, $time, $timeout);
            };
        }

        return false;
    }

    /**
     * 解锁
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @return bool
     */
    public static function unlock(string $lockKey, string $uuid): bool
    {
        /** @lang lua */
        $script = <<<EOF
if redis.call('get', KEYS[1]) == ARGV[1] then
    if redis.call('del', KEYS[1]) then
        if redis.call('lLen', KEYS[2]) == 0 then
            redis.call('lpush', KEYS[2], ARGV[1]);
        end
        redis.call('expire', KEYS[2], 10);
        return 1;
    end
end
return 0;
EOF;
        $redis  = get_inject_obj(RedisFactory::class)->get(config('app.dcslock_redis_pool', 'default'));
        $params = [$lockKey, $lockKey . '_WAIT', $uuid];
        $ret    = $redis->eval($script, $params, 2);
        if ($ret === false) {
            $ret = self::_unlock($redis, ...$params);
        }
        return $ret ? true : false;
    }

    private static function _unlock(RedisProxy $redis, string $lockKey, string $lockKeyWait, string $uuid)
    {
        Logger::stdoutLog()->warning('Current Redis Can\'t Execute Lua!!');
        if ($redis->get($lockKey) == $uuid) {
            if ($redis->del($lockKey)) {
                if ($redis->lLen($lockKeyWait) == 0) {
                    $redis->lpush($lockKeyWait, $uuid);
                }
                $redis->expire($lockKeyWait, 10);
                return true;
            }
        }
        return false;
    }

    public static function testLock()
    {
        $result  = null;
        $lockKey = 'testKey';
        //$uuid    = IdWorker::getIns(1, 1)->id() . mt_rand(100000, 999999);//"wantp/snowflake": "^1.2"
        $uuid = get_inject_obj(IdGeneratorInterface::class)->generate() . '';//"hyperf/snowflake": "~2.1.0",
        Context::set('RTIME', microtime(true));
        Context::set('RMEM', memory_get_usage());
        $isLock = self::lock($lockKey, $uuid, 3, -1);
        if ($isLock) {
            try {
                $result = ['is' => 'ok'];
                //Coroutine::sleep(5);
            } finally {
                self::unlock($lockKey, $uuid);
            }
            //$time = number_format(microtime(true) - Context::get('RTIME'), 4).'s';
            //$memory   = number_format((memory_get_usage() - Context::get('RMEM')) / 1024) . 'kb';
            //var_dump($uuid . ':' . 'OK Time:' . $time . ';MEM:' . $memory);
            return $result;
        }
        $time   = number_format(microtime(true) - Context::get('RTIME'), 4) . 's';
        $memory = number_format((memory_get_usage() - Context::get('RMEM')) / 1024) . 'kb';
        var_dump($uuid . ':' . 'Error Time:' . $time . ';MEM:' . $memory);
        throw new \RuntimeException('error');
    }
}