<?php

namespace Dleno\CommonCore\Tools;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Context\Context;
use Dleno\CommonCore\Tools\Server;
use Dleno\CommonCore\Tools\Crypt\OpenSslRsa2;
use Dleno\CommonCore\Conf\RequestConf;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;

class ApiServer
{
    /**
     * 是否Admin模块
     * @return bool
     */
    public static function isAdminModule()
    {
        if (!Context::has(RequestConf::REQUEST_ADMIN_MODULE)) {
            $isAdmin   = false;
            $adminName = config('app.admin_module_name') ?: 'admin';//默认 admin(空/未配置均回落)
            $mca       = Server::getRouteMca();
            $module    = get_array_val($mca, 'module');
            if (!empty($module)) {
                $isAdmin = strcasecmp((string)get_array_val($module, 0), $adminName) === 0;//不区分大小写
            } else {
                if (Context::has(ServerRequestInterface::class)) {
                    $request = get_inject_obj(ServerRequestInterface::class);
                    $path    = explode('/', $request->path());
                    if (!empty(config('app.route_prefix'))) {
                        $prefix = explode('/', trim(config('app.route_prefix'), '/'));
                        foreach ($prefix as $k => $pf) {
                            if ($pf == ($path[$k] ?? null)) {//前缀段数可能多于 path,避免未定义下标告警
                                unset($path[$k]);
                            }
                        }
                        $path = array_values($path);
                    }
                    $isAdmin = strcasecmp((string)get_array_val($path, 0), $adminName) === 0;//不区分大小写
                }
            }
            Context::set(RequestConf::REQUEST_ADMIN_MODULE, $isAdmin);
        }
        return Context::get(RequestConf::REQUEST_ADMIN_MODULE);
    }

    /**
     * 获取当前路由白名单值
     * @return int
     */
    public static function getRouteVal()
    {
        if (!Context::has(RequestConf::REQUEST_ROUTE_VAL)) {
            $val       = 0;
            $mca       = Server::getRouteMca();
            $macArr    = get_array_val($mca, 'module', ['']);
            $macArr[]  = get_array_val($mca, 'ctrl', '');
            $macArr[]  = get_array_val($mca, 'action', '');
            $whiteList = config('mca_white_list', []);
            //大小写不敏感比对:白名单键(module/class/function 各段)统一转小写,
            //避免配置与路由解析大小写不一致导致漏匹配。array_change_key_case 处理整段点号键。
            $whiteList = array_change_key_case($whiteList, CASE_LOWER);
            $routerNum = count($macArr);
            for ($i = $routerNum; $i > 0; $i--) {
                $checkMca = array_slice($macArr, 0, $i);
                $checkMca = strtolower(join('.', $checkMca));
                if (isset($whiteList[$checkMca])) {
                    $val = $whiteList[$checkMca];
                    break;
                }
            }
            Context::set(RequestConf::REQUEST_ROUTE_VAL, $val);
        }
        return Context::get(RequestConf::REQUEST_ROUTE_VAL);
    }

    /**
     * 获取当前请求的aes key
     * @return string
     */
    public static function getClientAesKey()
    {
        if (!Context::has(RequestConf::REQUEST_AES_KEY)) {
            $aesKey = get_header_val('Client-Key', '');
            if (!empty($aesKey)) {
                //RSA 私钥由调用方从配置(env)取出后传入,不在 OpenSslRsa2 内部读 config。
                //用 OpenSslRsa2(密文 base64,非 OpenSslRsa 的 bin2hex,密文长度约减半);客户端加密 Client-Key 须对应同款。
                $aesKey = OpenSslRsa2::decryptByPrivateKey($aesKey, (string) config('crypt.rsa.private_key', '')); //rsa解密key
            }
            Context::set(RequestConf::REQUEST_AES_KEY, $aesKey);
        }
        return Context::get(RequestConf::REQUEST_AES_KEY);
    }

    /**
     * 获取服务器完整域名地址
     * 容器/云 ALB 等反向代理场景:TLS 在代理层终止,后端连接为 http 且为内部端口，
     * 故优先采用代理注入的 X-Forwarded-* 还原真实公网协议/主机/端口，
     * 再回落 app_scheme 配置与请求自身的 Host/端口。
     * @return string
     **/
    public static function getServerDomain()
    {
        $request = get_inject_obj(RequestInterface::class);
        $uri     = $request->getUri();

        $xfProto = self::firstForwarded(get_header_val('X-Forwarded-Proto', ''));
        $xfHost  = self::firstForwarded(get_header_val('X-Forwarded-Host', ''));
        $xfPort  = self::firstForwarded(get_header_val('X-Forwarded-Port', ''));
        //仅在受信代理(ALB/网关)后才会注入 X-Forwarded-*;一旦出现即表明处于代理之后，
        //此时后端连接的 Host/端口为内部地址,不可作为公网地址依据
        $behindProxy = ($xfProto !== '' || $xfHost !== '' || $xfPort !== '');

        //协议:X-Forwarded-Proto → app_scheme 配置 → 请求协议 → http
        $scheme = strtolower($xfProto ?: config('app_scheme') ?: $uri->getScheme() ?: 'http');

        //主机:X-Forwarded-Host → 请求 Host;X-Forwarded-Host 可能自带端口
        $host     = $xfHost;
        $hostPort = null;
        if ($host !== '') {
            //形如 host:port(排除 IPv6 裸地址与方括号地址)时拆出端口
            if ($host[0] !== '[' && substr_count($host, ':') === 1) {
                [$host, $hostPort] = explode(':', $host, 2);
            }
        } else {
            $host = $uri->getHost();
        }

        //端口:X-Forwarded-Port → X-Forwarded-Host 自带端口 → (代理后忽略内部端口)请求端口 → 协议默认端口
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($xfPort !== '') {
            $port = (int)$xfPort;
        } elseif ($hostPort !== null) {
            $port = (int)$hostPort;
        } elseif ($behindProxy) {
            $port = $defaultPort;//代理后端的请求端口是内部端口,不可信
        } else {
            $port = (int)$uri->getPort();
        }
        $port = $port ?: $defaultPort;

        return $scheme . '://' . $host . ($port === $defaultPort ? '' : ':' . $port);
    }

    /**
     * 取 X-Forwarded-* 头的首个值(多级代理时为逗号分隔，最左为最初客户端值)
     */
    private static function firstForwarded(string $val): string
    {
        if ($val === '') {
            return '';
        }
        return trim(explode(',', $val)[0]);
    }
}
