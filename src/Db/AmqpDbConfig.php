<?php

namespace Dleno\CommonCore\Db;


use Dleno\CommonCore\Tools\Arrays\ArrayTool;

use function Hyperf\Support\env;

/**
 * Amqp配置
 * Class RpcClient
 * @package Dleno\CommonCore\Db
 */
class AmqpDbConfig
{
    private static $params     = [];
    private static $pool       = [];
    private static $concurrent = [];

    public static function getParams($poolName, $params = [])
    {
        if (empty($params)) {
            $params = self::getDefaultParams();
        } else {
            $params = ArrayTool::merge(self::getDefaultParams(), $params);
        }
        return $params;
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

    public static function getConcurrent($poolName, $concurrent = [])
    {
        if (empty($concurrent)) {
            $concurrent = self::getDefaultConcurrent();
        } else {
            $concurrent = ArrayTool::merge(self::getDefaultConcurrent(), $concurrent);
        }
        return $concurrent;
    }

    private static function getDefaultParams()
    {
        self::$params = [
            'insist'              => false,
            'login_method'        => 'AMQPLAIN',
            'login_response'      => null,
            'locale'              => 'en_US',
            'connection_timeout'  => (int)env('AMQP_CONNECT_TIMEOUT', 5),
            'read_write_timeout'  => (int)env('AMQP_READ_WRITE_TIMEOUT', 30),
            'context'             => null,
            'keepalive'           => true,
            'heartbeat'           => (int)env('AMQP_HEARTBEAT', 15),
            'close_on_destruct'   => false,
            'channel_rpc_timeout' => 0.0,
            'max_idle_channels'   => (int)env('AMQP_MAX_IDLE_CHANNELS', 10),
        ];
        return self::$params;
    }

    private static function getDefaultPool()
    {
        self::$pool = [
            'connections'     => (int)env('AMQP_CONNECTION', 2),
            'min_connections' => (int)env('AMQP_MIN_CONNECTION', 1),
            'max_connections' => (int)env('AMQP_MAX_CONNECTION', 10),//高并发下值越大，redis效率越高
            'connect_timeout' => (float)env('AMQP_CONNECT_TIMEOUT', 10.0),
            'wait_timeout'    => (float)env('AMQP_WAIT_TIMEOUT', 3.0),
            'heartbeat'       => (int)env('AMQP_HEARTBEAT', 15),
        ];
        return self::$pool;
    }

    private static function getDefaultConcurrent()
    {
        self::$concurrent = [
            'limit' => 1,
        ];
        return self::$concurrent;
    }
}