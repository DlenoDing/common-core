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

    /**
     * 获取当前请求/协程的运行耗时与内存增量;无请求上下文时返回安全默认值。
     */
    public static function runData()
    {
        //非请求上下文(队列/定时等没跑 InitMiddleware 的地方)调用时,REQUEST_RUN_START/MEM 可能未设;
        //给默认值(起点=当前时刻/当前内存 → 耗时/增量记为 0),避免 microtime - null = 巨值的脏数据。
        $runStart = Context::get(RequestConf::REQUEST_RUN_START) ?? microtime(true);
        $runMem   = Context::get(RequestConf::REQUEST_RUN_MEM) ?? memory_get_usage();

        $return = [];
        // 显示运行时间
        $return['time'] = number_format((microtime(true) - $runStart), 4) . 's';
        // 显示运行内存
        $return['memory']     = format_bytes(memory_get_usage() - $runMem);
        $return['memory_max'] = format_bytes(memory_get_peak_usage() - $runMem);
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
                                       random_int(1000000, 9999999);//CSPRNG:不可预测、降低碰撞(格式不变)
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

    /**
     * 检查 swoole_loader 授权域名是否覆盖当前请求 Host;未启用 swoole_loader 或本地 Host 默认放行。
     */
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
                        //!== false:strpos 命中位置 0 会被当 false，导致"宿主名以授权域开头"误判失败
                        if (strpos($requestHost, $hostname) !== false) {
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
