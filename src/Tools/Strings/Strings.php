<?php

namespace Dleno\CommonCore\Tools\Strings;

use Hashids\Hashids;
use Jenssegers\Optimus\Optimus;

class Strings
{
    //随机字符类型
    const RAND_TYPE_ALL    = 1;//所有
    const RAND_TYPE_NUM    = 2;//数字
    const RAND_TYPE_LETTER = 3;//字母

    // 密钥(0-9A-Za-z)打乱，可使用str_shuffle()函数重新生成
    const PRIVATE_KEY = 'cUzu7O4SsmTCAjtWDbnQyq1o3M0YG9eNi8ZHgwPLfr6RlvIXEpkhBaKJ5VdF2x';
    const AUTH_KEY    = 'GaYTbfvy';

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

    /**
     * 获取Optimus对象
     * @param int $prime
     * @param int $inverse
     * @param int $xor
     * @param int $size
     * @return Optimus
     */
    public static function getOptimus(
        int $prime = 2131560593,
        int $inverse = 1328003185,
        int $xor = 1688449735,
        int $size = Optimus::DEFAULT_SIZE
    ) {
        $optimus = new Optimus($prime, $inverse, $xor, $size);

        return $optimus;
    }

    /**
     * 获取Hashids对象
     * @param string $salt
     * @param int $minHashLength
     * @param null $alphabet
     * @return Hashids
     */
    public static function getHashids(
        $salt = 'FBRF6R6',
        $minHashLength = 0,
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'
    ) {
        if (empty($alphabet)) {
            $hashids = new Hashids($salt, $minHashLength);
        } else {
            $hashids = new Hashids($salt, $minHashLength, $alphabet);
        }
        return $hashids;
    }

    /**
     * 自定义加解密方法
     */
    public static function authcode($string, $decode = true, $key = '', $expiry = 0)
    {
        // 动态密匙长度，相同的明文会生成不同密文就是依靠动态密匙
        $ckeyLength = 4;
        // 密匙
        $key = md5($key ? $key : self::AUTH_KEY);
        // 密匙a会参与加解密
        $keya = md5(substr($key, 0, 16));
        // 密匙b会用来做数据完整性验证
        $keyb = md5(substr($key, 16, 16));
        // 密匙c用于变化生成的密文
        $keyc = $ckeyLength ? ($decode ? substr($string, 0, $ckeyLength) :
            substr(md5(microtime()), -$ckeyLength)) : '';
        // 参与运算的密匙
        $cryptkey  = $keya . md5($keya . $keyc);
        $keyLength = strlen($cryptkey);
        // 明文，前10位用来保存时间戳，解密时验证数据有效性，10到26位用来保存$keyb(密匙b)，
        //解密时会通过这个密匙验证数据完整性
        // 如果是解码的话，会从第$ckeyLength位开始，因为密文前$ckeyLength位保存 动态密匙，以保证解密正确
        $string       = $decode ? base64_decode(substr($string, $ckeyLength)) :
            sprintf('%010d', $expiry ? $expiry + time() : 0) .
            substr(md5($string . $keyb), 0, 16) . $string;
        $stringLength = strlen($string);
        $result       = '';
        $box          = range(0, 255);
        $rndkey       = array();
        // 产生密匙簿
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $keyLength]);
        }
        // 用固定的算法，打乱密匙簿，增加随机性，好像很复杂，实际上对并不会增加密文的强度
        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        // 核心加解密部分
        for ($a = $j = $i = 0; $i < $stringLength; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            // 从密匙簿得出密匙进行异或，再转成字符
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }
        if ($decode) {
            // 验证数据有效性，请看未加密明文的格式
            if ((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) &&
                substr($result, 10, 16) ==
                substr(md5(substr($result, 26) . $keyb), 0, 16)
            ) {
                return substr($result, 26);
            } else {
                return '';
            }
        } else {
            // 把动态密匙保存在密文里，这也是为什么同样的明文，生产不同密文后能解密的原因
            // 因为加密后的密文可能是一些特殊字符，复制过程可能会丢失，所以用base64编码
            return $keyc . str_replace('=', '', base64_encode($result));
        }
    }

    /**
     * 数字加密
     * @param int $int
     * @param string|null $key
     * @return bool|string
     */
    public static function encode($int, $key = null)
    {
        //判断是否为整型
        if (!is_int($int)) {
            return false;
        }
        if (empty($key)) {
            $key = self::PRIVATE_KEY;
        }
        //将传入数字转换成十六进制分割成数组
        $hexArr = str_split(dechex($int));
        //将密钥分割成数组
        $keyArr = str_split($key);
        //密钥长度，推荐62
        $keyLen = count($keyArr);
        //随机数字
        $rand = mt_rand(0, $keyLen - 1);
        //将随机值压入结果开头
        $str = $keyArr[$rand];
        //验证码
        $verify = $keyArr[($keyLen - $rand + strlen($int)) % $keyLen];
        //循环十六进制每一位数字，替换成密钥里的值
        foreach ($hexArr as $v) {
            $offset = hexdec($v) + $rand;
            $str    .= $keyArr[$offset % $keyLen];
        }
        //将验证码压入结果末尾并返回
        return $str . $verify;
    }

    /**
     * 数字解密
     * @param string|$str
     * @param string|null $key
     * @return bool|int
     */
    public static function decode($str, $key = null)
    {
        //验证$str是否合法
        if (!preg_match('/^[0-9a-zA-Z]{2,10}$/', $str)) {
            return false;
        }
        //将传入字符串分割成数组
        $strArr = str_split($str);
        //密钥
        if (empty($key)) {
            $key = self::PRIVATE_KEY;
        }
        //将密钥分割成数组
        $keyArr = str_split($key);
        //密钥长度
        $keyLen = count($keyArr);
        //十六进制数值
        $hex = '';
        //获取随机数
        $rand = strpos($key, array_shift($strArr));
        //获取验证码
        $verify = array_pop($strArr);
        //循环每一个字串并转换成十六进制
        foreach ($strArr as $k => $v) {
            if (strpos($key, $v) >= $rand) {
                $hex .= dechex(strpos($key, $v) - $rand);
            } else {
                $hex .= dechex($keyLen - $rand + strpos($key, $v));
            }
        }
        //十六进制转换成十进制
        $dec = hexdec($hex);
        //判断验证码是否正确
        if ($verify !== $keyArr[($keyLen - $rand + strlen($dec)) % $keyLen]) {
            return false;
        }
        return $dec;
    }
}
