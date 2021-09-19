<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Fibonacci;

class Rc4
{
    /**
     * rc4加密
     *
     * @param string $data
     * @return string
     */
    public static function enrc4($data)
    {
        $key = md5($data);
        return self::rc4($key, $data) . $key;
    }

    /**
     * rc4解密
     *
     * @param string $data
     * @return string
     */
    public static function derc4($data)
    {
        $key  = substr($data, strlen($data) - 32, strlen($data));
        $data = substr($data, 0, strlen($data) - 32);
        return self::rc4($key, $data);
    }

    /**
     * RC4算法
     * 对称加密，加解密使用同一套函数
     *
     * @param string $pwd 密钥
     * @param string $data 需加密字符串
     * @return string
     */
    private static function rc4($pwd, $data)
    {
        $key[]       = "";
        $box[]       = "";
        $cipher      = '';
        $pwd_length  = strlen($pwd);
        $data_length = strlen($data);
        for ($i = 0; $i < 127; $i++) {
            $key[$i] = ord(@$pwd[$i % $pwd_length]);
            $box[$i] = $i;
        }
        for ($j = $i = 0; $i < 128; $i++) {
            $j       = ($j + @$box[$i] + @$key[$i]) % 256;
            $tmp     = @$box[$i];
            $box[$i] = @$box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $data_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + @$box[$a]) % 256;
            $tmp     = @$box[$a];
            $box[$a] = @$box[$j];
            $box[$j] = $tmp;
            $k       = @$box[((@$box[$a] + @$box[$j]) % 256)];
            $cipher  .= chr(ord(@$data[$i]) ^ $k);
        }
        return $cipher;
    }
}
