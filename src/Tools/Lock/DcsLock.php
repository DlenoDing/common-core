<?php

namespace Dleno\CommonCore\Tools\Lock;

use Dleno\CommonCore\Tools\Logger;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hyperf\Snowflake\IdGeneratorInterface;
use Hyperf\Context\Context;
use Swoole\Coroutine as SwooleCo;

use function Hyperf\Config\config;

class DcsLock
{
    private static $isWarning = false;

    private static $unlock = [];

    //续约 expire 瞬时失败的重试间隔(毫秒)。远小于续约安全缓冲(恒 ~2s)，
    //以便在锁 TTL 到期前的窗口内多次重试，抢在过期前续上。
    private const RENEWAL_RETRY_MS = 500;

    //是否支持浮点阻塞超时（进程级缓存：Redis 6.0+ 服务端 + phpredis 5.3.0+ 客户端）
    private static ?bool $floatTimeout = null;

    /**
     * 锁主键的物理名：用 hash tag {} 包住 $lockKey。
     * 集群下 unlock 的 Lua（EVAL）一次性操作锁主键(KEYS[1])与 _WAIT 等待键(KEYS[2])，
     * 两键必须落同一 slot，否则报 CROSSSLOT。realKey/waitKey 的 tag 内容同为 $lockKey → 同 slot。
     * 注：phpredis OPT_PREFIX 自动前置在 {} 之外，不进入 tag，两键 tag 仍一致，故 OPT_PREFIX 下依然安全。
     * 因 Lua 与单键命令(set/get/ttl/expire/blPop)必须操作同一物理键，所有物理键站点统一经此换算；
     * 内存映射 self::$unlock 的数组键仍用逻辑 $lockKey（非 Redis key，不加 tag）。
     * @param string $lockKey 逻辑锁 key
     */
    private static function realKey(string $lockKey): string
    {
        return '{' . $lockKey . '}';
    }

    /**
     * 等待队列键的物理名：与 realKey 共享同一 hash tag（$lockKey）以同 slot。
     * @param string $lockKey 逻辑锁 key
     */
    private static function waitKey(string $lockKey): string
    {
        return '{' . $lockKey . '}_WAIT';
    }

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
            //自动续期(所持有时间10秒及以上的才自动续期)
            if ($time >= 10) {
                $renewalTime = ($time - 2) * 1000;
                $cid         = SwooleCo::getCid();
                //首次续约与续约链后续排期统一走 scheduleRenewal（延迟=renewalTime）
                self::scheduleRenewal($lockKey, $uuid, $time, $renewalTime, $cid, $renewalTime);
            }
            //自动解锁
            \Hyperf\Coroutine\defer(
                function () use ($lockKey, $uuid) {
                    self::unlock($lockKey, $uuid);
                }
            );
        }
        return $result;
    }

    /**
     * 锁自动续约
     * @param $lockKey
     * @param $uuid
     * @param $time
     * @param $renewalTime
     * @param $cid
     */
    private static function renewalLock($lockKey, $uuid, $time, $renewalTime, $cid)
    {
        if (!isset(self::$unlock[$lockKey . '::' . $uuid])) {
            return;
        }
        //执行加锁的协程不存在，则自动解锁，防止加锁协程异常中断，又未执行defer
        if (!SwooleCo::exists($cid)) {
            self::unlock($lockKey, $uuid);
            return;
        }

        try {
            $redis  = get_inject_obj(RedisFactory::class)->get(
                config('app.dcslock_redis_pool', 'default')
            );
            $result = self::renewalExpire($redis, $lockKey, $uuid, $time);
        } catch (\Throwable $e) {
            //瞬时异常(连接抖动/连接池耗尽等):绝不放弃锁——业务仍在临界区、流程不可控，
            //放弃会让他人进临界区破坏互斥(比锁提前过期更糟)。改用远小于安全缓冲的间隔重试，
            //抢在 TTL 到期前续上;重试仍走回本方法，顶部两道闸会重新判定(已解锁/协程已亡则自然停)。
            Logger::stdoutLog()->warning("DcsLock renewal expire exception [{$lockKey}]: " . $e->getMessage());
            self::scheduleRenewal($lockKey, $uuid, $time, $renewalTime, $cid, self::RENEWAL_RETRY_MS);
            return;
        }

        if (empty($result)) {
            //value 校验 Lua 返回 0：key 已不存在(到期/被解)或已被他人重获——锁已不归自己。
            //再续既救不回、又可能误续他人锁 → 停止续约并告警(既成事实，只能让上层知情)。
            Logger::stdoutLog()->warning("DcsLock renewal: lock lost or not owned, stop renewing [{$lockKey}]");
            return;
        }

        //正常续上，排下一次常规续约
        //sleep方式某些情况下会导致::all coroutines (count: *) are asleep - deadlock!
        self::scheduleRenewal($lockKey, $uuid, $time, $renewalTime, $cid, $renewalTime);
    }

    /**
     * 续约 expire：value 校验后再 expire（仅当 key 的 value 仍是本协程 uuid 才续期），
     * 原子防止「误续他人锁」。Lua 完全不可用时降级为裸 expire(不校验 value，尽力而为)。
     * @param RedisProxy $redis Redis 连接
     * @param string $lockKey 逻辑锁 key
     * @param string $uuid 持锁唯一标识
     * @param int $time 锁持有时间(秒)
     * @return int|false 1=已续上；0=key 不存在或非本协程持有(应停止续约)；false 仅降级路径下 Lua 不可用时不会出现(已回退裸 expire)
     */
    private static function renewalExpire(RedisProxy $redis, string $lockKey, string $uuid, int $time)
    {
        /** @lang lua */
        $script = <<<EOF
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('expire', KEYS[1], ARGV[2]);
end
return 0;
EOF;
        $ret = self::evalLua($redis, $script, [self::realKey($lockKey), $uuid, $time], 1);
        if ($ret === false) {
            //Lua 完全不可用降级:退回裸 expire(不校验 value，与 _unlock 降级同等限制)
            $ret = $redis->expire(self::realKey($lockKey), $time);
        }
        return $ret;
    }

    /**
     * 排定下一次续约 Timer。
     * @param string $lockKey 逻辑锁 key
     * @param string $uuid 持锁唯一标识
     * @param int $time 锁持有时间(秒)
     * @param int $renewalTime 常规续约间隔毫秒
     * @param int $cid 持锁协程 ID
     * @param int $delay 延迟毫秒(正常=renewalTime；瞬时失败重试=RENEWAL_RETRY_MS)
     */
    private static function scheduleRenewal($lockKey, $uuid, $time, $renewalTime, $cid, int $delay)
    {
        \Swoole\Timer::after(
            $delay,
            function () use ($lockKey, $uuid, $time, $renewalTime, $cid) {
                self::renewalLock($lockKey, $uuid, $time, $renewalTime, $cid);
            }
        );
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
        if ($redis->set(self::realKey($lockKey), $uuid, ['NX', 'EX' => $time])) {
            self::$unlock[$lockKey . '::' . $uuid] = true;
            return true;
        }
        if ($timeout === 0) {
            return false;
        }
        $wait = $timeout;
        if ($timeout > 0 && ($ttl = $redis->ttl(self::realKey($lockKey)) * 1000) > 0 && $ttl < $timeout) {
            $wait = $ttl;
        }
        $popTime = microtime(true);
        if (self::supportFloatTimeout($redis)) {
            //Redis 6+ 支持浮点秒阻塞超时，保留亚秒精度（最多3位小数），避免被放大到整秒
            $blockSec = round($wait / 1000, 3);
            if ($blockSec <= 0) {
                $blockSec = 0.001;
            }
        } else {
            //老版本只支持整秒，维持原有行为
            $blockSec = intval($wait > 1000 ? round($wait / 1000) : 1);
        }
        $pop  = $redis->blPop(self::waitKey($lockKey), $blockSec);
        $wait = (int)round($blockSec * 1000);
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
        $params = [self::realKey($lockKey), self::waitKey($lockKey), $uuid];
        $ret    = self::evalLua($redis, $script, $params, 2);
        if ($ret === false) {
            $ret = self::_unlock($redis, ...$params);
        }
        unset(self::$unlock[$lockKey . '::' . $uuid]);
        return $ret ? true : false;
    }

    /**
     * 执行 Lua 脚本：优先 EVALSHA（按 SHA1 调用，省去每次重传完整脚本），
     * EVALSHA 失败（脚本未缓存，如 Redis 重启/首次调用）时回退 EVAL（执行同时会缓存脚本，
     * 后续即可命中 EVALSHA）。
     *
     * 注：Hyperf 连接池下每次命令可能落在不同连接，无法依赖 getLastError 判断 NOSCRIPT，
     * 故以「EVALSHA 返回 false 即回退 EVAL」的方式兜底（脚本正常返回 0/1 不为 false，不会误触发）。
     *
     * @param RedisProxy $redis Redis 连接
     * @param string $script Lua 脚本
     * @param array $params KEYS+ARGV 参数
     * @param int $numKeys KEYS 数量
     * @return mixed 脚本返回值；Lua 完全不可用时返回 false
     */
    private static function evalLua(RedisProxy $redis, string $script, array $params, int $numKeys)
    {
        //按脚本各自算 SHA1（短串 sha1 微秒级，相对 Redis RTT 可忽略）。
        //务必 per-script：本类有多个脚本(unlock/续约)，若共用单槽 SHA 会用错脚本的哈希
        //去 EVALSHA，导致执行成另一脚本（如续约误跑成 unlock 删锁）。
        $sha = sha1($script);
        $ret = $redis->evalSha($sha, $params, $numKeys);
        if ($ret === false) {
            $ret = $redis->eval($script, $params, $numKeys);
        }
        return $ret;
    }

    /**
     * 检测是否可使用浮点阻塞超时（结果进程级缓存，仅首次探测一次）。
     * 需同时满足：Redis 服务端 >= 6.0.0、phpredis 客户端 >= 5.3.0（可传 double 超时）。
     * @param RedisProxy $redis Redis 连接
     */
    private static function supportFloatTimeout(RedisProxy $redis): bool
    {
        if (self::$floatTimeout !== null) {
            return self::$floatTimeout;
        }
        $extOk = version_compare((string)phpversion('redis'), '5.3.0', '>=');
        $serverOk = false;
        if ($extOk) {
            $info    = $redis->info('server');
            $version = is_array($info) ? ($info['redis_version'] ?? '0') : '0';
            $serverOk = version_compare((string)$version, '6.0.0', '>=');
        }
        self::$floatTimeout = $extOk && $serverOk;
        return self::$floatTimeout;
    }

    private static function _unlock(RedisProxy $redis, string $lockKey, string $lockKeyWait, string $uuid)
    {
        if (!self::$isWarning) {
            Logger::stdoutLog()
                  ->warning('Current Redis Can\'t Execute Lua!!');
            self::$isWarning = true;
        }
        //此分支为 Redis 不支持 Lua(EVAL) 时的降级兜底。以下为多条独立命令，非原子操作：
        //get 与 del 之间若锁恰好 EX 到期并被其他客户端抢占，del 仍会无条件删除当前 key（可能误删他人锁），
        //无法提供与 Lua 脚本等价的原子安全性，仅作尽力而为的释放。
        if ($redis->get($lockKey) === $uuid) {
            //与 Lua 行为对齐：get 命中即视为持锁方，del 的返回值不参与后续判断
            //（Lua 中 del 返回整数 0 同样为真值，仍会继续唤醒等待队列）
            $redis->del($lockKey);
            if ($redis->lLen($lockKeyWait) == 0) {
                $redis->lpush($lockKeyWait, $uuid);
            }
            $redis->expire($lockKeyWait, 10);
            return true;
        }
        return false;
    }

    /**
     * @internal 仅用于分布式锁本地调试验证,不作为业务 API。
     */
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
        Logger::stdoutLog()->warning($uuid . ':' . 'Error Time:' . $time . ';MEM:' . $memory);
        throw new \RuntimeException('error');
    }
}
