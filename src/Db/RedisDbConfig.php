<?php

namespace Dleno\CommonCore\Db;


use Dleno\CommonCore\Tools\Arrays\ArrayTool;

/**
 * Redis数据库配置
 * Class RpcClient
 * @package Dleno\CommonCore\Db
 */
class RedisDbConfig
{
    private static $options = [];
    private static $pool    = [];

    public static function getOptions($poolName, $options = [])
    {
        if (empty($options)) {
            $options = self::getDefaultOptions();
        } else {
            $options = ArrayTool::merge(self::getDefaultOptions(), $options);
        }
        return $options;
    }

    public static function getPool($poolName, $pool = [])
    {
        if (empty($pool)) {
            $pool = self::getDefaultPool();
        } else {
            $pool = ArrayTool::merge(self::getDefaultPool(), $pool);
        }
        return $pool;
    }

    private static function getDefaultOptions()
    {
        self::$options = [
            \Redis::OPT_PREFIX       => env('APP_NAME', 'PluginBox-API') . ':', //socket-io暂时不支持key前缀
            \Redis::OPT_READ_TIMEOUT => '-1',//读连接不超时，SWOOLE默认60秒
        ];


        return self::$options;
    }

    private static function getDefaultPool()
    {
        self::$pool = [
            'min_connections' => (int)env('REDIS_MIN_CONNECTION', 10),
            'max_connections' => (int)env('REDIS_MAX_CONNECTION', 100),//高并发下值越大，redis效率越高
            'connect_timeout' => (float)env('REDIS_CONNECT_TIMEOUT', 10.0),
            'wait_timeout'    => (float)env('REDIS_WAIT_TIMEOUT', 3.0),
            'heartbeat'       => (int)env('REDIS_HEARTBEAT', -1),
            'max_idle_time'   => (float)env('REDIS_MAX_IDLE_TIME', 60.0),
        ];


        return self::$pool;
    }
}