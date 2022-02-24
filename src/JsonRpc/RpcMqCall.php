<?php

namespace Dleno\CommonCore\JsonRpc;

use Hyperf\Context\Context;
use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Conf\RpcConsumersConf;
use Dleno\CommonCore\Conf\RpcContextConf;
use Dleno\CommonCore\Exception\ServerException;
use Dleno\CommonCore\Tools\Amqp\Producer;
use Dleno\CommonCore\Tools\Arrays\ArrayTool;
use Dleno\CommonCore\Tools\Server;

/**
 * rpc MQ异步调用
 * Class RpcMqCall
 * @package Dleno\CommonCore\JsonRpc
 */
class RpcMqCall
{
    const DEFAULT_RETRY_NUM = 3;

    public static function producerRpc(
        string $producerName,
        array $data,
        array $callback = [],
        int $retry = self::DEFAULT_RETRY_NUM
    ) {
        $message = new $producerName(
            [
                'number' => 1,
                'retry'  => $retry,
                'data'   => $data,
            ]
        );
        $result  = Producer::send($message, true);
        if (!$result) {
            if ($callback) {
                $class    = $callback[0];
                $isStatic = (new \ReflectionMethod($class, $callback[1]))->isStatic();
                $className = is_object($class) ? get_class($class) : $class;
                try {
                    if ($isStatic) {
                        $class = $className;
                        $class::{$callback[1]}($data);
                    } else {
                        $class = is_object($class) ? $class : get_inject_obj($class);
                        $class->{$callback[1]}($data);
                    }
                } catch (\Throwable $t) {
                    throw new ServerException($className.'::'.$callback[1]."\r\n".$t->getMessage());
                    //return false;
                }
            }
        }

        return true;
    }

    public static function consumerRpc(array $callback, array $data, string $producerName = '', $delay = 5)
    {
        $class    = $callback[0];
        $isStatic = (new \ReflectionMethod($class, $callback[1]))->isStatic();
        $className = is_object($class) ? get_class($class) : $class;
        try {
            rpc_context_set(RpcContextConf::TRACE_ID, Server::getTraceId());//请求号
            Context::destroy(RequestConf::REQUEST_TRACE_ID);
            if ($isStatic) {
                $class = $className;
                $class::{$callback[1]}($data['data']);
            } else {
                $class = is_object($class) ? $class : get_inject_obj($class);
                $class->{$callback[1]}($data['data']);
            }
        } catch (\Throwable $e) {
            if ($producerName) {
                //重试
                $retry  = $data['retry'] ?? self::DEFAULT_RETRY_NUM;
                $number = ($data['number'] ?? 1);
                $number = $number <= 0 ? 1 : $number;
                $number = $number + 1;
                if ($number <= $retry) {
                    $data['number'] = $number;
                    \go(function() use ($producerName, $data, $delay) {
                        Coroutine::sleep($delay);
                        $message = new $producerName($data);
                        Producer::send($message, true);
                    });
                }
            }
            throw new ServerException($className.'::'.$callback[1]."\r\n".$e->getMessage());
        }
    }
}