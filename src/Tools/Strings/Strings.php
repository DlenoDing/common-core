<?php

namespace Dleno\CommonCore\Tools\Strings;

class Strings
{
    //随机字符类型
    const RAND_TYPE_ALL    = 1;//所有
    const RAND_TYPE_NUM    = 2;//数字
    const RAND_TYPE_LETTER = 3;//字母

    /**
     * 随机生成指定长度的字符串
     * @return string
     */
    public static function makeRandStr($len = 6, $type = self::RAND_TYPE_ALL)
    {
        if ($type == self::RAND_TYPE_ALL) {
            $chars = 'abcdefghijklmnpqrstuvwxyz0123456789';
        } elseif ($type == self::RAND_TYPE_NUM) {
            $chars = "0123456789";
        } elseif ($type == self::RAND_TYPE_LETTER) {
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

    /**
     * 格式化金额为要显示的字符串
     * @param float $price 金额，单位为元
     * @param int $decimals 小数位数
     * @param string $format 格式化串
     * @return string
     */
    public static function formatPrice($price, $decimals = 2, $format = null)
    {
        $price = number_format($price, $decimals);
        if (empty($format)) {
            $format = '￥%s';
        }
        return sprintf($format, $price);
    }
}
