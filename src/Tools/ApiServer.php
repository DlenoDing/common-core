<?php

namespace Dleno\CommonCore\Tools;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Context\Context;
use Dleno\CommonCore\Tools\Server;
use Dleno\CommonCore\Tools\Crypt\OpenSslRsa;
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
            $isAdmin = false;
            $mca     = Server::getRouteMca();
            $module  = get_array_val($mca, 'module');
            if (!empty($module)) {
                $isAdmin = get_array_val($module, 0) === config('app.admin_module_name') ? true : false;
            } else {
                if (Context::has(ServerRequestInterface::class)) {
                    $request = get_inject_obj(ServerRequestInterface::class);
                    $path    = explode('/', $request->path());
                    if (!empty(config('app.route_prefix'))) {
                        $prefix = explode('/', trim(config('app.route_prefix'), '/'));
                        foreach ($prefix as $k => $pf) {
                            if ($pf == $path[$k]) {
                                unset($path[$k]);
                            }
                        }
                        $path = array_values($path);
                    }
                    $isAdmin = ucfirst(get_array_val($path, 0)) === config('app.admin_module_name') ? true : false;
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
            $routerNum = count($macArr);
            for ($i = $routerNum; $i > 0; $i--) {
                $checkMca = array_slice($macArr, 0, $i);
                $checkMca = join('.', $checkMca);
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
                $aesKey = OpenSslRsa::decryptByPrivateKey($aesKey); //rsa解密key
            }
            Context::set(RequestConf::REQUEST_AES_KEY, $aesKey);
        }
        return Context::get(RequestConf::REQUEST_AES_KEY);
    }

    /**
     * 获取服务器完整域名地址
     * @return string
     **/
    public static function getServerDomain()
    {
        $request = get_inject_obj(RequestInterface::class);
        $scheme  = config('app_scheme');
        $port    = $request->getUri()
                           ->getPort();
        $port    = $port ?: ($scheme == 'https' ? 443 : 80);


        return $scheme . '://' . $request->getUri()
                                         ->getHost() . (in_array($port, [80, 443]) ? '' : ':' . $port);
    }
}
