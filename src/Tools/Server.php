<?php

namespace Dleno\CommonCore\Tools;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Context\Context;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Conf\RpcContextConf;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

class Server
{
    /**
     * 是否正式环境
     * @return bool
     */
    public static function isProd()
    {
        if (env('IS_PROD', false)) {
            return true;
        }
        if (config('app_env') == 'prod') {
            return true;
        }
        return false;
    }

    public static function runData()
    {
        $return = [];
        // 显示运行时间
        $return['time'] = number_format((microtime(true) - Context::get(RequestConf::REQUEST_RUN_START)), 4) . 's';
        // 显示运行内存
        $return['memory']     = format_bytes(memory_get_usage() - Context::get(RequestConf::REQUEST_RUN_MEM));
        $return['memory_max'] = format_bytes(memory_get_peak_usage() - Context::get(RequestConf::REQUEST_RUN_MEM));
        return $return;
    }

    /**
     * MCA:module ctrl action
     * @return array
     */
    public static function getRouteMca()
    {
        if (!Context::has(RequestConf::REQUEST_MCA)) {
            $mca = [];
            if (Context::has(ServerRequestInterface::class)) {
                $request = get_inject_obj(ServerRequestInterface::class);
                /** @var Dispatched $dispatched */
                $dispatched = $request->getAttribute(Dispatched::class);
                if (is_object($dispatched) && !is_null($dispatched->handler)) {
                    $callback = $dispatched->handler->callback;
                    // 兼容在routes.php 里面写匿名函数调用的情况
                    if ($callback instanceof \Closure) {
                        $mca['module'] = ['closureModule'];
                        $mca['ctrl']   = 'closureCtrl';
                        $mca['action'] = 'closureAction';
                    } else {
                        if (!is_array($callback)) {
                            $callback = [$callback];
                        }
                        $moduleCtrl = join('\\', $callback);
                        $moduleCtrl = str_replace('Controller\\', '\\', $moduleCtrl);
                        $moduleCtrl = explode('\\', $moduleCtrl);
                        unset($moduleCtrl[0]);
                        $moduleCtrl   = array_values(array_filter($moduleCtrl));
                        $moduleCtrlCt = count($moduleCtrl);

                        $mca['module'] = array_slice($moduleCtrl, 0, $moduleCtrlCt - 2);
                        $mca['ctrl']   = get_array_val($moduleCtrl, $moduleCtrlCt - 2);
                        $mca['action'] = get_array_val($moduleCtrl, $moduleCtrlCt - 1);
                    }
                }
            }
            Context::set(RequestConf::REQUEST_MCA, $mca);
        }
        return Context::get(RequestConf::REQUEST_MCA);
    }

    /**
     * 获取当前请求的traceId
     * @return int
     */
    public static function getTraceId()
    {
        if (!Context::has(RequestConf::REQUEST_TRACE_ID)) {
            $traceId = get_header_val('Trace-Id');
            if (empty($traceId)) {
                $traceId = rpc_context_get(RpcContextConf::TRACE_ID);
                $traceId = $traceId ?? (Context::get(RequestConf::REQUEST_RUN_START) ?? microtime(true)) . '.' .
                                       mt_rand(1000000, 9999999);
            }
            Context::set(RequestConf::REQUEST_TRACE_ID, $traceId);
        }
        return Context::get(RequestConf::REQUEST_TRACE_ID);
    }

    /**
     * 获取服务器IP地址
     * @return string
     **/
    public static function getIpAddr()
    {
        $getArr = [
            'eth1',
            'eth0'
        ];
        $ips    = swoole_get_local_ip();
        if (isset($ips['lo'])) {
            unset($ips['lo']);
        }
        if (count($ips) == 0) {
            return 'UnKnown';
        }
        foreach ($getArr as $wk) {
            if (isset($ips[$wk])) {
                return $ips[$wk];
            }
        }
        reset($ips);
        return current($ips);
    }

    /**
     * 获取服务器MAC地址
     * @return string
     **/
    public static function getMacAddr()
    {
        $getArr = [
            'eth1',
            'eth0'
        ];
        $macs   = swoole_get_local_mac();
        if (isset($macs['lo'])) {
            unset($macs['lo']);
        }
        if (count($macs) == 0) {
            return 'UnKnown';
        }
        foreach ($getArr as $wk) {
            if (isset($macs[$wk])) {
                return $macs[$wk];
            }
        }
        reset($macs);
        return current($macs);
    }

    public static function swooleLoaderCheck()
    {
        if (!extension_loaded('swoole_loader')) {
            return true;
        }
        $request = get_inject_obj(RequestInterface::class);
        if (!($request instanceof RequestInterface)) {
            return true;
        }
        $requestHost = $request->getUri()
                               ->getHost();
        if (empty($requestHost)) {
            return true;
        }
        $licenseData = swoole_get_license();
        $licenseData = ($licenseData ?? []) ?: [];

        if (!in_array($requestHost, ['localhost', '127.0.0.1'])) {
            foreach ($licenseData as $license) {
                $hostnames = explode(',', $license['hostname']);
                foreach ($hostnames as $hostname) {
                    if (substr($hostname, 0, 1) === '*') {
                        $hostname = substr($hostname, 1);
                        if (strpos($requestHost, $hostname)) {
                            return true;
                        }
                    } else {
                        if ($requestHost == $hostname) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }
        return true;
    }
}
