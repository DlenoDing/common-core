<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Crypt;

class OpenSslCrypt
{
    //加密方式
    const
        DES_ECB = 'DES-ECB',
        DES_EDE3 = 'DES-EDE3',
        DES_CBC = 'DES-CBC',
        AES_128_ECB = 'AES-128-ECB',
        AES_192_ECB = 'AES-192-ECB',
        AES_256_ECB = 'AES-256-ECB',
        AES_128_CBC = 'AES-128-CBC',
        AES_256_CBC = 'AES-256-CBC';

    //可以不需要iv参数的加密方式
    public static $noIvMethods = [
        self::DES_ECB,
        self::DES_EDE3,
        self::AES_128_ECB,
        self::AES_192_ECB,
        self::AES_256_ECB,
    ];

    /**
     * des加密,PKCS5Padding填充
     * @param $str
     * @param $key
     * @param string $method
     * @param string $iv
     * @return string
     */
    public static function encrypt($str, $key, $method = self::AES_256_ECB, $iv = '')
    {
        if (!in_array($method, self::$noIvMethods) && empty($iv)) {
            return false;
        }
        $str = self::pkcs5Pad($str, 16);
        if (strlen($str) % 16) {
            $str = str_pad($str, strlen($str) + 16 - strlen($str) % 16, "\0");
        }
        return base64_encode(openssl_encrypt($str, $method, $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv));
    }

    /**
     * des解密
     * @param $str
     * @param $key
     * @param string $method
     * @param string $iv
     * @return bool|string
     */
    public static function decrypt($str, $key, $method = self::AES_256_ECB, $iv = '')
    {
        if (!in_array($method, self::$noIvMethods) && empty($iv)) {
            return false;
        }
        $decodeStr = openssl_decrypt(base64_decode($str), $method, $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv);
        if (!$decodeStr) {
            return false;
        }
        return self::pkcs5Unpad($decodeStr);
    }

    /**
     * PKCS5Padding填充
     * @param $text
     * @param $blocksize
     * @return string
     */
    private static function pkcs5Pad($text, $blocksize)
    {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }

    /**
     * PKCS5Padding填充逆向
     * @param $text
     * @return bool|string
     */
    private static function pkcs5Unpad($text)
    {
        $len  = strlen($text);
        $char = substr($text, $len - 1, 1);
        $pad  = ord($char);
        if ($pad > $len) {
            return false;
        }
        if (strspn($text, chr($pad), $len - $pad) != $pad) {
            return false;
        }
        return substr($text, 0, -1 * $pad);
    }
}

/*
$iv = openssl_random_pseudo_bytes(16);
$key = 'sdsfdsgfdwretgreffftryhtrgre';
$str = '华夏站在世界之巅！！';
$encrypt = \App\Tools\Crypt\OpenSslCrypt::encrypt($str, $key);
var_dump($encrypt);
$decrypt = \App\Tools\Crypt\OpenSslCrypt::decrypt($encrypt, $key);
var_dump($decrypt);
*/
