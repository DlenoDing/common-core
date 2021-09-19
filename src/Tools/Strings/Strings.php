<?php

namespace Dleno\CommonCore\Tools\Strings;

class Strings
{
    /**
     * 随机生成指定长度的字符串
     * @return string
     */
    public static function makeRandStr($len = 6, $type = 'all')
    {
        if ($type == 'all') {
            $chars = 'abcdefghijklmnpqrstuvwxyz0123456789';
        } elseif ($type == 'num') {
            $chars = "0123456789";
        } elseif ($type == 'letter') {
            $chars = "abcdefghijklmnpqrstuvwxyz";
        }
        mt_srand((double)microtime() * mt_rand(1000000, 9999999) * getmypid());
        $randstr = '';
        while (strlen($randstr) < $len) {
            $randstr .= substr($chars, (mt_rand() % strlen($chars)), 1);
        }
        return $randstr;
    }

    /**
     * 解析字符串中的变量
     * */
    public static function parseVariable($str, $parseData)
    {
        $search  = array_keys($parseData);
        $replace = array_values($parseData);
        /* 解析其中变量 */
        array_walk(
            $search,
            function (&$str) {
                $str = "{" . $str . "}";
            }
        );
        $parseData = str_replace($search, $replace, $str);
        return $parseData;
    }
}
