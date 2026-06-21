<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Crypt;

/**
 * 通用对称加解密工具(AES/DES,PKCS7 填充,base64 输出)。
 *
 * 密钥由调用方传入。默认 **AES-256-CBC**;需 IV 的模式(CBC 等)若未传 IV,则用 key 派生 IV
 * (取/补足到该模式所需的 IV 长度)。ECB 等无需 IV 的模式忽略 IV。
 * 加解密失败统一返回 false。
 */
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

    //无需 iv 参数的加密方式
    public static $noIvMethods = [
        self::DES_ECB,
        self::DES_EDE3,
        self::AES_128_ECB,
        self::AES_192_ECB,
        self::AES_256_ECB,
    ];

    /**
     * 加密(PKCS7 填充)。
     * @param string $str    明文
     * @param string $key    密钥
     * @param string $method 算法(默认 AES-256-CBC)
     * @param string $iv     IV(CBC 等需要;留空则用 key 派生)
     * @return string|false base64 密文;失败 false
     */
    public static function encrypt($str, $key, $method = self::AES_256_CBC, $iv = '')
    {
        $iv = self::resolveIv($method, $key, $iv);
        if ($iv === false) {
            return false;
        }
        $str = self::pkcs5Pad((string) $str, 16);
        $enc = openssl_encrypt($str, $method, $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv);
        if ($enc === false) {
            return false;
        }
        return base64_encode($enc);
    }

    /**
     * 解密。
     * @param string $str    base64 密文
     * @param string $key    密钥
     * @param string $method 算法(默认 AES-256-CBC)
     * @param string $iv     IV(留空则用 key 派生)
     * @return string|false 明文;失败 false
     */
    public static function decrypt($str, $key, $method = self::AES_256_CBC, $iv = '')
    {
        $iv = self::resolveIv($method, $key, $iv);
        if ($iv === false) {
            return false;
        }
        $dec = openssl_decrypt(base64_decode((string) $str), $method, $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv);
        if ($dec === false) {
            return false;
        }
        return self::pkcs5Unpad($dec);
    }

    /**
     * IV 解析:无需 IV 的模式返回 '';需 IV 而未传 → 用 key 派生(取/补足到所需长度)。
     * @return string|false 失败(未知算法)返回 false
     */
    private static function resolveIv($method, $key, $iv)
    {
        if (in_array($method, self::$noIvMethods, true)) {
            return '';
        }
        $ivLen = @openssl_cipher_iv_length($method);
        if ($ivLen === false) {
            return false;
        }
        if ($iv === '' || $iv === null) {
            $iv = (string) $key; //未传 IV → 用 key 作 IV
        }
        //规整到所需长度:不足补 \0,超出截断
        return substr(str_pad((string) $iv, $ivLen, "\0"), 0, $ivLen);
    }

    /**
     * PKCS7 填充
     */
    private static function pkcs5Pad($text, $blocksize)
    {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }

    /**
     * PKCS7 去填充
     * @return bool|string 非法填充返回 false
     */
    private static function pkcs5Unpad($text)
    {
        $len  = strlen($text);
        if ($len === 0) {
            return false;
        }
        $char = substr($text, $len - 1, 1);
        $pad  = ord($char);
        if ($pad < 1 || $pad > $len) {
            return false;
        }
        if (strspn($text, chr($pad), $len - $pad) != $pad) {
            return false;
        }
        return substr($text, 0, -1 * $pad);
    }
}
