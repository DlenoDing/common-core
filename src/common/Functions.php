<?php

declare(strict_types=1);

/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Tools\Client;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Hyperf\WebSocketServer\Context as WsContext;

if (!function_exists('get_array_val')) {
    /**
     * 获取数组指定元素值
     * @param array $array
     * @param $key
     * @param null $default
     * @return mixed|null
     */
    function get_array_val(array $array, $key, $default = null)
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }
}

if (!function_exists('get_server_val')) {
    /**
     * 获取指定server值
     * @param string $key
     * @param null $default
     * @return mixed|null
     */
    function get_server_val($key, $default = null)
    {
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
            $val     = $request->server(strtolower($key), $default);
            return $val;
        }
        return $default;
    }
}

if (!function_exists('get_header_val')) {
    /**
     * 获取指定header值
     * @param string $key
     * @param null $default
     * @return mixed|null
     */
    function get_header_val($key, $default = null)
    {
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
            return $request->header($key, $default);
        } elseif (class_exists(WsContext::class)) {
            if (WsContext::has(ServerRequestInterface::class)) {
                $request = WsContext::get(ServerRequestInterface::class);
                if ($request->hasHeader($key)) {
                    return $request->getHeaderLine($key);
                }
            }
        }

        return $default;
    }
}

if (!function_exists('get_post_val')) {
    /**
     * 获取指定post值
     * @param string $key
     * @param null $default
     * @return mixed|null
     */
    function get_post_val($key, $default = null)
    {
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
            $val     = $request->post($key, $default);
            return $val;
        }
        return $default;
    }
}

if (!function_exists('get_query_val')) {
    /**
     * 获取指定query值
     * @param string $key
     * @param null $default
     * @return mixed|null
     */
    function get_query_val($key, $default = null)
    {
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
            $val     = $request->query($key, $default);
            return $val;
        }
        return $default;
    }
}

if (!function_exists('array_to_json')) {
    /**
     * 数组转JSON
     * @param array $array
     * @return false|string
     */
    function array_to_json(array $array)
    {
        return \json_encode($array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('json_to_array')) {
    /**
     * json转数组
     * @param $json
     * @return array
     */
    function json_to_array($json, $assoc = true)
    {
        return $json ? (array)\json_decode($json, $assoc) : [];
    }
}

if (!function_exists('get_inject_obj')) {
    /**
     * 获取注入对象
     * @param string $key
     * @return mixed Entry.
     */
    function get_inject_obj($key)
    {
        return ApplicationContext::getContainer()
                                 ->get($key);
    }
}

if (!function_exists('set_inject_obj')) {
    /**
     * 获取注入对象
     * @param string $key
     * @return mixed Entry.
     */
    function set_inject_obj($key, $entry)
    {
        return ApplicationContext::getContainer()
                                 ->set($key, $entry);
    }
}

if (!function_exists('rpc_service_get')) {
    /**
     * 获取rpc指定服务
     * @param string $service
     * @return mixed Entry.
     */
    function rpc_service_get($service)
    {
        return get_inject_obj($service);

        /*$key = 'SERVICE_CLIENT::' . $service;
        if (!Context::has($key)) {
            $client = new \Dleno\CommonCore\JsonRpc\RpcClient($service);
            Context::set($key, $client);
        }
        return Context::get($key);*/
    }
}

if (!function_exists('rpc_context_get')) {
    /**
     * 获取rpc上下文指定字段
     * @param string $key
     * @return mixed|null
     */
    function rpc_context_get($key, $default = null)
    {
        if (class_exists('\\Hyperf\\Rpc\\Context')) {
            $val = get_inject_obj(\Hyperf\Rpc\Context::class)->get($key);
            $val = $val ?? $default;
            return $val;
        }
        return $default;
    }
}

if (!function_exists('rpc_context_set')) {
    /**
     * 设置rpc上下文指定字段值
     * @param string $key
     * @param mixed|null $val
     * @return mixed|null
     */
    function rpc_context_set($key, $val)
    {
        if (class_exists('\\Hyperf\\Rpc\\Context')) {
            get_inject_obj(\Hyperf\Rpc\Context::class)->set($key, $val);
            return true;
        }
        return false;
    }
}

if (!function_exists('date_zone')) {
    /**
     * 将对应$fromZone的时间戳转换成$toZone的日期数据（默认服务器时区转到客户端时区）
     * @param string $format 时间格式，如：Y-m-d H:i:s
     * @param int $timestamp 时间戳
     * @param string $fromZone America/Denver
     * @param string $toZone Asia/Shanghai
     * @return string|int
     */
    function date_zone($format, $timestamp, $toZone = null, $fromZone = null)
    {
        $fromZone    = $fromZone ?? config('app.default_time_zone', 'UTC');
        $toZone      = $toZone ?? Client::getTimezone();
        $dateTime    = date(GlobalConf::DATE_TIME_FORMAT, $timestamp);
        $dateTimeObj = new \DateTime($dateTime, new DateTimeZone($fromZone));
        $dateTimeObj->setTimezone(new DateTimeZone($toZone));
        return $dateTimeObj->format($format);
    }
}

if (!function_exists('zone_date')) {
    /**
     * 将对应$fromZone的时间戳转换成$toZone的日期数据（默认客户端时区转到服务器时区）
     * @param string $dateTime 日期时间字符串（2021-01-14|2021-01-14 00:00:00）
     * @param string $format 输出格式
     * @param string $fromZone America/Denver
     * @param string $toZone Asia/Shanghai
     * @return string|int
     */
    function zone_date($dateTime, $format = 'U', $fromZone = null, $toZone = null)
    {
        $fromZone = $fromZone ?? Client::getTimezone();
        $toZone   = $toZone ?? config('app.default_time_zone', 'UTC');
        $datetime = new \DateTime($dateTime, new DateTimeZone($fromZone));
        $datetime->setTimezone(new DateTimeZone($toZone));
        return $datetime->format($format);
    }
}

if (!function_exists('dynamic_rpc_service_get')) {
    /**
     * 动态获取rpc服务（自动注册代理）
     * @param string $serviceName
     * @param string $interfaceClass
     * @param array $node
     * @return mixed Entry.
     */
    function dynamic_rpc_service_get(string $serviceName, string $interfaceClass, array $node = [])
    {
        static $service = [];
        if (!isset($service[$serviceName])) {
            $config    = get_inject_obj(\Hyperf\Contract\ConfigInterface::class);
            $consumers = $config->get('services.consumers', []);
            $consumer  = get_array_val($consumers, 0);
            if (empty($consumer)) {
                throw new \RuntimeException('Empty Services Consumers!!');
            }
            $consumer['name']    = $serviceName;
            $consumer['service'] = $consumer['id'] = $interfaceClass;
            $consumer['nodes']   = $node;

            $consumers[] = $consumer;
            $config->set('services.consumers', $consumers);

            $serviceFactory = get_inject_obj(\Hyperf\RpcClient\ProxyFactory::class);

            /** @var Hyperf\Di\Definition\DefinitionSourceInterface $definitions */
            $definitions = ApplicationContext::getContainer()
                                             ->getDefinitionSource();

            $proxyClass = $serviceFactory->createProxy($interfaceClass);

            $definitions->addDefinition(
                $consumer['name'],
                function (\Psr\Container\ContainerInterface $container) use ($consumer, $interfaceClass, $proxyClass) {
                    return new $proxyClass(
                        $container,
                        $consumer['name'],
                        $consumer['protocol'] ?? 'jsonrpc-http',
                        [
                            'load_balancer'     => $consumer['load_balancer'] ?? 'random',
                            'service_interface' => $interfaceClass,
                        ]
                    );
                }
            );

            $service[$serviceName] = $serviceName;
        }

        return rpc_service_get($serviceName);
    }
}

if (!function_exists('get_app_service_hook')) {
    /**
     * 获取app服务钩子
     * @param string $appKey
     * @param array $node
     * @return \Dleno\InterfaceService\Contracts\AppCommon\CommonHookContractInterface
     */
    function get_app_service_hook($appKey, array $node = [])
    {
        $appKey  = ucfirst($appKey);
        $serviceName = 'Service.' . $appKey . '.CommonHookService';
        if (empty($node)) {
            $node = \Dleno\CommonCore\JsonRpc\RpcConsumers::getNode($serviceName);
            if (empty($node)) {
                $node = [
                    ['host' => $appKey . '.Rpc-Service', 'port' => 9504],
                ];
            }
        }
        $service = dynamic_rpc_service_get(
            $serviceName,
            \Dleno\InterfaceService\Contracts\AppCommon\CommonHookContractInterface::class,
            $node
        );
        return $service;
    }
}

if (!function_exists('catch_fatal_error_8888')) {
    /**
     * 捕获系统Fatal error错误
     */
    function catch_fatal_error_8888()
    {
        $error = error_get_last();
        if (!isset($error['type'])) {
            return true;
        }
        switch ($error['type']) {
            case E_ERROR:
            case E_WARNING:
            case E_PARSE:
                //case E_NOTICE:
            case E_CORE_ERROR:
            case E_CORE_WARNING:
            case E_COMPILE_ERROR:
            case E_COMPILE_WARNING:
            case E_STRICT:
            case E_RECOVERABLE_ERROR:
                break;
            default:
                return true;
        }

        if (!ApplicationContext::hasContainer()) {
            /** @var Psr\Container\ContainerInterface $container */
            $container = require BASE_PATH . '/config/container.php';
            if (class_exists(Dleno\HyperfEnvMulti\MultiEnvListener::class)) {
                $container->get(Dleno\HyperfEnvMulti\MultiEnvListener::class)
                          ->process(new Hyperf\Framework\Event\BootApplication());
            }
        }
        $server = config('app_name') . '(' . \Dleno\CommonCore\Tools\Server::getIpAddr() . ')';

        //发送钉钉消息
        \Dleno\CommonCore\Tools\Notice\DingDing::send(
            [
                '启动错误'    => null,
                'Server'  => $server,
                'File'    => str_replace(BASE_PATH, '', $error["file"]),
                'Line'    => $error["line"],
                'Message' => str_replace(BASE_PATH, '', $error["message"]),
            ]
        );
    }

    //注册
    register_shutdown_function('catch_fatal_error_8888');
}
