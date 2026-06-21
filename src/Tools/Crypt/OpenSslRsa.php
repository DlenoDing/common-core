<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Crypt;

/**
 * 通用 RSA 分块加解密工具。
 *
 * 密钥一律由**调用方传入**(统一用 **base64(PEM)** 形式,便于在配置/两系统间单行传输;
 * 本类用前内部解码回 PEM,也容错直接传原始 PEM)——本类不内置默认密钥、也不读 config,
 * 不同业务调用点可各用各的密钥/证书。密文按"密钥位数"自适应分块(兼容 1024/2048/4096)。
 * openssl 调用失败返回 false(不再静默拼出垃圾)。
 */
class OpenSslRsa
{
    //明文分块字节数(对 base64 后的数据分块;须 < 该位数密钥可容纳的最大明文,2048 位 PKCS1 上限约 245)
    const ENCRYPT_LEN = 32;

    /**
     * 私钥加密
     * @param string $dataContent 明文
     * @param string $privateKey  私钥 PEM(必传)
     * @return string|false hex 密文;失败 false
     */
    public static function encryptedByPrivateKey($dataContent, string $privateKey)
    {
        return self::chunkEncrypt($dataContent, $privateKey, true);
    }

    /**
     * 私钥解密
     * @param string $encrypted  hex 密文
     * @param string $privateKey 私钥 PEM(必传)
     * @return string|false 明文;失败 false
     */
    public static function decryptByPrivateKey($encrypted, string $privateKey)
    {
        return self::chunkDecrypt($encrypted, $privateKey, true);
    }

    /**
     * 公钥加密
     * @param string $dataContent 明文
     * @param string $publicKey   公钥 PEM(必传)
     * @return string|false hex 密文;失败 false
     */
    public static function encryptedByPublicKey($dataContent, string $publicKey)
    {
        return self::chunkEncrypt($dataContent, $publicKey, false);
    }

    /**
     * 公钥解密
     * @param string $encrypted hex 密文
     * @param string $publicKey 公钥 PEM(必传)
     * @return string|false 明文;失败 false
     */
    public static function decryptByPublicKey($encrypted, string $publicKey)
    {
        return self::chunkDecrypt($encrypted, $publicKey, false);
    }

    /**
     * @param bool $usePrivate true=私钥加密;false=公钥加密
     * @return string|false
     */
    private static function chunkEncrypt($dataContent, string $key, bool $usePrivate)
    {
        $key         = self::pem($key);
        $dataContent = base64_encode((string) $dataContent);
        $encrypted   = '';
        $total       = strlen($dataContent);
        for ($pos = 0; $pos < $total; $pos += self::ENCRYPT_LEN) {
            $chunk = substr($dataContent, $pos, self::ENCRYPT_LEN);
            $ok    = $usePrivate
                ? openssl_private_encrypt($chunk, $out, $key)
                : openssl_public_encrypt($chunk, $out, $key);
            if (!$ok) {
                return false;
            }
            $encrypted .= bin2hex($out);
        }
        return $encrypted;
    }

    /**
     * @param bool $usePrivate true=私钥解密;false=公钥解密
     * @return string|false
     */
    private static function chunkDecrypt($encrypted, string $key, bool $usePrivate)
    {
        $key      = self::pem($key);
        $keyBytes = self::keyBytes($key, $usePrivate);
        if ($keyBytes <= 0) {
            return false;
        }
        $hexBlock = $keyBytes * 2;            //密文一块 = 密钥字节数 → bin2hex 后的 hex 长度
        $decrypt  = '';
        $total    = strlen((string) $encrypted);
        for ($pos = 0; $pos < $total; $pos += $hexBlock) {
            $bin = @hex2bin(substr((string) $encrypted, $pos, $hexBlock));
            if ($bin === false) {
                return false;
            }
            $ok = $usePrivate
                ? openssl_private_decrypt($bin, $out, $key)
                : openssl_public_decrypt($bin, $out, $key);
            if (!$ok) {
                return false;
            }
            $decrypt .= $out;
        }
        return base64_decode($decrypt);
    }

    /**
     * 密钥参数统一为 base64(PEM)(两系统间传输用 base64);此处解码回 PEM 供 openssl 使用。
     * 容错:若传入的本就是原始 PEM(以 -----BEGIN 开头),原样返回。
     */
    private static function pem($key): string
    {
        $key = (string) $key;
        if (str_starts_with(ltrim($key), '-----BEGIN')) {
            return $key;
        }
        $decoded = base64_decode($key, true);
        return $decoded === false ? $key : $decoded;
    }

    /**
     * 由密钥推导其字节数(位数/8),用于密文分块。
     * @return int 失败返回 0
     */
    private static function keyBytes(string $key, bool $usePrivate): int
    {
        $res = $usePrivate ? openssl_pkey_get_private($key) : openssl_pkey_get_public($key);
        if ($res === false) {
            return 0;
        }
        $details = openssl_pkey_get_details($res);
        $bits    = $details['bits'] ?? 0;
        return intval($bits / 8);
    }
}
