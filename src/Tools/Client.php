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
        $ip = rpc_context_get(RpcContextConf::CLIENT_IP, '');
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
                            //XFF 惯例为 "client, proxy1, proxy2"(逗号后带空格);必须 trim,
                            //否则 filter_var(FILTER_VALIDATE_IP) 对 " 8.8.8.8" 判 false → 漏掉真实公网客户端 IP、退回 Remote_Addr。
                            $ip = trim($ips[$i]);
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

    /**
     * 阿里云 SLB RemoteIp 过滤:空值/unknown/阿里云保留段返回 false。
     * @param string $ip
     * @return bool
     */
    public static function checkIpByAli($ip)
    {
        if (empty($ip) || strcasecmp($ip, 'unknown') == 0) {
            return false;
        }
        //排除阿里云保留IP
        return preg_match("/^(100\\.64|100\\.251)\\./i", $ip) ? false : true;
    }

    /**
     * 判断是否为合法公网 IP。私网、保留地址、unknown、非法格式均返回 false。
     * @param string $ip
     * @return bool
     */
    public static function checkIp($ip)
    {
        if (empty($ip) || strcasecmp($ip, 'unknown') == 0) {
            return false;
        }
        //「公网客户端 IP」才返回 true,用于从 X-Forwarded-For 链里跳过内网代理地址。
        //改用 filter_var 同时:① 校验是合法 IP;② 排除私网(10/8、172.16/12、192.168/16、IPv6 fc00::/7);
        //③ 排除保留(0/8、127/8 回环、169.254/16 链路本地、240/4、IPv6 ::1 等)。
        //原正则只字面匹配 `172.16.`,漏掉 172.17–172.31(/12 其余段,含 Docker 默认 172.17.x 等),
        //且不校验格式(非法 IP 串会被误判为公网)。CGNAT 100.64/10 不在排除内,与 checkIpByAli 分工不变。
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * 获取客户端设备号
     */
    public static function getDevice()
    {
        $device = rpc_context_get(RpcContextConf::CLIENT_DEVICE, '');
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
        $language = rpc_context_get(RpcContextConf::LANGUAGE, '');
        if (empty($language)) {
            $languageKey = 'Client-Language';
            //优先取header
            $language = get_header_val($languageKey, '');
            //再取get参数
            if (empty($language)) {
                $language = get_query_val($languageKey, '');
            }
            //都没有则取客户端的可接收语言
            if (empty($language)) {
                $language = get_header_val('Accept-Language', '');
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
        $timezone = rpc_context_get(RpcContextConf::TIMEZONE, '');
        if (empty($timezone)) {
            $timezoneKey = 'Client-Timezone';
            //优先取header
            $timezone = get_header_val($timezoneKey, '');
            //再取get参数
            if (empty($timezone)) {
                $timezone = get_query_val($timezoneKey, '');
            }
            if (empty($timezone)) {
                $timezone = config('app.default_time_zone', 'UTC');
            }
        }

        return $timezone;
    }
}
