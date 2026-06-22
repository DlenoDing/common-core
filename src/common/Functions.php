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

use Dleno\CommonCore\Tools\Client;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
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
        $default = !is_null($default) ? strval($default) : $default;
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

if (!function_exists('go_run')) {
    /**
     * @param array|callable $callbacks
     */
    function go_run($callbacks)
    {
        if (!\Hyperf\Coroutine\Coroutine::inCoroutine()) {
            \Hyperf\Coroutine\run($callbacks);
        } else {
            \Hyperf\Coroutine\go($callbacks);
        }
    }
}

if (!function_exists('array_to_json')) {
    /**
     * 数组转JSON
     * @param array $array
     * @return false|string
     */
    function array_to_json(
        array $array,
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    ) {
        return \json_encode($array, $options);
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
        //只对真正的"空"短路(空串/null/false);原用 $json? 会把字符串 '0' 也当空 → 误返 [](应为 [0])。
        if ($json === '' || $json === null || $json === false) {
            return [];
        }
        return (array)\json_decode($json, $assoc);
    }
}

if (!function_exists('array_to_xml')) {
    /**
     * 数组转xml
     * @param array $data
     * @param SimpleXMLElement|null $xml
     * @param $parentKey
     * @return false|string
     */
    function array_to_xml(array $data, ?\SimpleXMLElement $xml = null, $parentKey = null)
    {
        $isRoot = ($xml === null);
        if ($isRoot) {
            $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><xml></xml>');
        }
        foreach ($data as $key => $value) {
            //数字键(list 元素)用父级名作元素名;顶层数字键无父名时兜底 'item'，
            //避免 addChild(null) 在 PHP8 抛 TypeError(addChild 第一参须为非空 string)。
            $numericName = $parentKey ?? 'item';
            if (is_array($value)) {
                if (is_numeric($key)) {
                    array_to_xml($value, $xml->addChild($numericName), $numericName);
                } else {
                    if (array_is_list($value)) {
                        array_to_xml($value, $xml, $key);
                    } else {
                        array_to_xml($value, $xml->addChild($key), $key);
                    }
                }
            } else {
                $name = is_numeric($key) ? $numericName : $key;
                $xml->addChild($name, htmlspecialchars($value . ''));
            }
        }
        //仅在根部把整棵树序列化为字符串;内层递归只需借 addChild 建树(其返回值本就被忽略),
        //避免每层都 asXML() 重复序列化子树(嵌套越深浪费越大)。
        return $isRoot ? $xml->asXML() : $xml;
    }
}

if (!function_exists('xml_to_array')) {
    /**
     * xml转数组
     * @param $xml
     * @return array
     */
    function xml_to_array($xml)
    {
        $xml = (string) $xml;
        if ($xml === '') {
            return [];
        }
        //LIBXML_NONET:禁止解析时访问网络(纵深防御 XXE/SSRF；现代 libxml≥2.9 默认已不展开外部实体，
        //此处不加 LIBXML_NOENT 即不会解析实体，再加 NONET 显式封网)。
        $prev = libxml_use_internal_errors(true);          //抑制畸形 XML 的 PHP Warning，改入可控错误队列
        $obj  = simplexml_load_string($xml, null, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($obj === false) {
            return [];                                     //畸形/无法解析 → 返回 []，避免向下传 false
        }
        $arr = json_to_array(json_encode($obj));
        if (!is_array($arr)) {
            return $arr;                                   //标量根(如 <root>text</root>)原样返回
        }
        //SimpleXML→json 把"空节点"(<a></a>/<a/>)表示为空对象 {} → 解码成空数组 []；
        //统一归一化为空字符串 ''(只转"作为值的空数组"，非空数组/列表递归进去；顶层容器保持数组，
        //保证调用方 foreach 安全)。
        $emptyToStr = function ($v) use (&$emptyToStr) {
            if (!is_array($v)) {
                return $v;
            }
            if ($v === []) {
                return '';
            }
            foreach ($v as $k => $vv) {
                $v[$k] = $emptyToStr($vv);
            }
            return $v;
        };
        foreach ($arr as $k => $v) {
            $arr[$k] = $emptyToStr($v);
        }
        return $arr;
    }
}

if (!function_exists('array_is_list')) {
    /**
     * 判断数组是否是列表数据
     * @param $value
     * @return bool
     */
    function array_is_list($value)
    {
        //与 PHP8.1 原生 array_is_list 同义:键须为从 0 开始的连续整数。
        //注:不能用 isset 判断——isset 对值为 null 的元素返 false，会把 [null, 1] 误判为非 list；
        //此处按顺序 === 比对键(本 polyfill 仅在 PHP<8.1 无原生时才生效)。
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
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
     * 设置/覆盖容器内的注入对象
     * @param string $key
     * @param mixed $entry
     * @return void
     */
    function set_inject_obj($key, $entry)
    {
        ApplicationContext::getContainer()
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
     * 将时间戳格式化为 $toZone 时区的日期数据（默认转到客户端时区）。
     * 注:时间戳是绝对时刻(UTC 纪元秒)、本身不带时区,故无"来源时区"参数。
     * @param string $format 时间格式，如：Y-m-d H:i:s
     * @param int $timestamp 时间戳
     * @param string $toZone Asia/Shanghai（默认客户端时区）
     * @return string
     */
    function date_zone($format, $timestamp, $toZone = null)
    {
        //用 @时间戳 构造(UTC)再 setTimezone 到目标时区格式化;
        //不受"服务器进程时区 与 app.default_time_zone 是否一致"的影响(原实现 date()+按 fromZone 重解释会引入偏移)。
        $toZone      = $toZone ?? Client::getTimezone();
        $dateTimeObj = (new \DateTime('@' . (int)$timestamp))->setTimezone(new DateTimeZone($toZone));
        return $dateTimeObj->format($format);
    }
}

if (!function_exists('zone_date')) {
    /**
     * 将 $fromZone 时区的日期时间字符串转换到 $toZone 后按 $format 输出（默认客户端时区转服务器时区）。
     * @param string $dateTime 日期时间字符串（2021-01-14|2021-01-14 00:00:00）
     * @param string $format 输出格式
     * @param string $fromZone America/Denver
     * @param string $toZone Asia/Shanghai
     * @return string
     */
    function zone_date($dateTime, $format = 'U', $fromZone = null, $toZone = null)
    {
        $fromZone = $fromZone ?? Client::getTimezone();
        $toZone   = $toZone ?? \Hyperf\Config\config('app.default_time_zone', 'UTC');
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
     * @param array $registry
     * @return mixed Entry.
     */
    function dynamic_rpc_service_get(
        string $serviceName,
        string $interfaceClass,
        array $node = [],
        array $registry = []
    ) {
        static $service = [];
        if (!isset($service[$serviceName])) {
            $config    = get_inject_obj(\Hyperf\Contract\ConfigInterface::class);
            $consumers = $config->get('services.consumers', []);
            $consumer  = get_array_val($consumers, 0);
            if (empty($consumer)) {
                throw new \RuntimeException('Empty Services Consumers!!');
            }
            $consumer['name']     = $serviceName;
            $consumer['service']  = $consumer['id'] = $interfaceClass;
            $consumer['registry'] = $registry;
            $consumer['nodes']    = $node;

            $consumers[] = $consumer;
            $config->set('services.consumers', $consumers);

            $serviceFactory = get_inject_obj(\Hyperf\RpcClient\ProxyFactory::class);

            $container = ApplicationContext::getContainer();

            $proxyClass = $serviceFactory->createProxy($interfaceClass);

            $container->define(
                $consumer['name'],
                function (\Psr\Container\ContainerInterface $container) use (
                    $consumer,
                    $interfaceClass,
                    $proxyClass
                ) {
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

if (!function_exists('format_bytes')) {
    /**
     * 字节数转可读容量字符串。
     */
    function format_bytes($bytes, $precision = 2)
    {
        $units = array("b", "kb", "mb", "gb", "tb");

        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . " " . $units[$pow];
    }
}

if (!function_exists('second2txt')) {
    /**
     * 秒数转中文时长文本。
     */
    function second2txt($second)
    {
        $d = floor($second / (3600 * 24));
        $h = floor(($second % (3600 * 24)) / 3600);
        $m = floor((($second % (3600 * 24)) % 3600) / 60);
        $s = $second - ($d * 24 * 3600) - ($h * 3600) - ($m * 60);

        return (empty($d) ? '' : $d . '天') . (empty($h) ? '' : $h . '时') . (empty($m) ? '' : $m . '分') . (empty($s) ? '' : $s . '秒');
    }
}

if (!function_exists('number_to_letter')) {
    /**
     * 正整数转 Excel 列名风格字母序号(1=A, 26=Z, 27=AA)。
     */
    function number_to_letter($number)
    {
        $str = '';
        while ($number > 0) {
            $number--;
            $rmd    = $number % 26;
            $str    = chr(65 + $rmd) . $str;
            $number = intval($number / 26);
        }
        return $str;
    }
}

if (!function_exists('str_to_time')) {
    /**
     * 日期时间字符串转时间戳;空值或无法解析时返回 0。
     */
    function str_to_time($strTime)
    {
        if (empty($strTime)) {
            return 0;
        }
        if (is_numeric($strTime)) {
            return intval($strTime);
        }
        $result = strtotime($strTime);
        if (empty($result)) {
            try {
                $date   = new \DateTime($strTime);
                $result = $date->format('U');
            } catch (\Throwable $e) {
                $result = 0;
            }
        }
        return $result;
    }
}
