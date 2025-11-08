<?php

namespace Dleno\CommonCore\Tools;

use Dleno\CommonCore\Conf\RpcContextConf;

use function Hyperf\Config\config;

class Client
{
    /**
     * 获取IP
     */
    public static function getIP()
    {
        $ip = rpc_context_get(RpcContextConf::CLIENT_IP);
        if (empty($ip)) {
            $ip = get_header_val('Client-Ip', '');
            if (!self::checkIp($ip)) {
                //阿里云slb
                $ip = get_header_val('RemoteIp', '');
                if (!self::checkIp($ip) || !self::checkIpByAli($ip)) {
                    //代理
                    $forwarded = get_header_val('X-Forwarded-For', '');
                    if (!empty($forwarded)) {
                        $ips = explode(',', $forwarded);
                        for ($i = 0; $i < count($ips); $i++) {
                            $ip = $ips[$i];
                            if (self::checkIp($ip)) {//排除局域网ip
                                break;
                            }
                        }
                    }
                    //客户端IP
                    if (!self::checkIp($ip)) {
                        $ip = get_server_val('Remote_Addr', '');
                    }
                }
            }
        }
        return $ip;
    }

    public static function checkIpByAli($ip)
    {
        if (empty($ip) || strcasecmp($ip, 'unknown') == 0) {
            return false;
        }
        //排除阿里云保留IP
        return preg_match("/^(100\\.64|100\\.251)\\./i", $ip) ? false : true;
    }

    public static function checkIp($ip)
    {
        if (empty($ip) || strcasecmp($ip, 'unknown') == 0) {
            return false;
        }
        return preg_match("/^(10|172\\.16|192\\.168)\\./i", $ip) ? false : true;
    }

    /**
     * 获取客户端设备号
     */
    public static function getDevice()
    {
        $device = rpc_context_get(RpcContextConf::CLIENT_DEVICE);
        if (empty($device)) {
            $device = get_header_val('Client-Device', '');
        }
        return $device;
    }

    /**
     * 获取语言
     */
    public static function getLang()
    {
        //优先取RPC请求传递的值
        $language = rpc_context_get(RpcContextConf::LANGUAGE);
        if (empty($language)) {
            $languageKey = 'Client-Language';
            //优先取header
            $language = get_header_val($languageKey);
            //再取get参数
            if (empty($language)) {
                $language = get_query_val($languageKey);
            }
            //都没有则取客户端的可接收语言
            if (empty($language)) {
                $language = get_header_val('Accept-Language');
                $language = explode(';', $language);
                $language = explode(',', $language[0]);
                $language = str_replace('-', '_', $language[0]);
            }
            if (empty($language)) {
                $language = config('translation.locale');
            }
            if (in_array(strtolower($language), ['zh'])) {
                $language = 'zh_CN';
            }
        }

        return $language;
    }

    /**
     * 获取时区
     */
    public static function getTimezone()
    {
        //优先取RPC请求传递的值
        $timezone = rpc_context_get(RpcContextConf::TIMEZONE);
        if (empty($timezone)) {
            $timezoneKey = 'Client-Timezone';
            //优先取header
            $timezone = get_header_val($timezoneKey);
            //再取get参数
            if (empty($timezone)) {
                $timezone = get_query_val($timezoneKey);
            }
            if (empty($timezone)) {
                $timezone = config('app.default_time_zone', 'UTC');
            }
        }

        return $timezone;
    }
}
