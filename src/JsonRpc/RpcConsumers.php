<?php

namespace Dleno\CommonCore\JsonRpc;

use Dleno\CommonCore\Conf\RpcConsumersConf;
use Dleno\CommonCore\Tools\Arrays\ArrayTool;

/**
 * rpc服务消费者
 * Class RpcClient
 * @package Dleno\CommonCore\JsonRpc
 */
class RpcConsumers
{
    private static $registry = [];
    private static $options  = [];

    public static function getOptions($serviceName, $options = [])
    {
        if (empty($options)) {
            $options = self::getDefaultOptions();
        } else {
            $options = ArrayTool::merge(self::getDefaultOptions(), $options);
        }
        return $options;
    }

    public static function getNode($serviceName)
    {
        if (RpcConsumersConf::RPC_REGISTRY) {
            return [];
        }
        $serviceName = self::getServiceName($serviceName);
        if (env('APP_ENV', '') == 'local') {
            $node = RpcConsumersConf::$localNodes[$serviceName] ?? [];
        } else {
            $node = RpcConsumersConf::$nodes[$serviceName] ?? [];
        }
        if (empty($node)) {
            return [];
        }

        return $node;
    }

    public static function getRegistry($serviceName)
    {
        if (!RpcConsumersConf::RPC_REGISTRY) {
            return [];
        }

        self::$registry = [
            'protocol' => 'consul',
            'address'  => env('CONSUL_SERVER_URI', 'http://localhost:8500'),
        ];

        return self::$registry;
    }

    private static function getDefaultOptions()
    {
        self::$options = [
            'connect_timeout' => (float)env('RPC_CONNECT_TIMEOUT', 5.0),
            'recv_timeout'    => (float)env('RPC_RECV_TIMEOUT', 5.0),
            'settings'        => [
                'open_eof_split' => true,
                'package_eof'    => "\r\n",
            ],
            // 当使用 JsonRpcPoolTransporter 时会用到以下配置
            'pool'            => [
                'min_connections' => (int)env('RPC_MIN_CONNECTION', 10),
                'max_connections' => (int)env('RPC_MAX_CONNECTION', 100),
                'connect_timeout' => (float)env('RPC_CONNECT_TIMEOUT', 5.0),
                'wait_timeout'    => (float)env('RPC_WAIT_TIMEOUT', 3.0),
                'heartbeat'       => (int)env('RPC_HEARTBEAT', -1),
                'max_idle_time'   => (float)env('RPC_MAX_IDLE_TIME', 60.0),
            ],
        ];


        return self::$options;
    }

    private static function getServiceName($serviceName)
    {
        $serviceName = join('.', array_slice(explode('.', $serviceName), 0, 2));
        return $serviceName;
    }
}