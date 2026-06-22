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
    /**
     * 私钥加密
     * @param string $dataContent 明文
     * @param string $privateKey  必传私钥,可为 base64(PEM) 或原始 PEM
     * @return string|false hex 密文;失败 false
     */
    public static function encryptedByPrivateKey($dataContent, string $privateKey)
    {
        return self::chunkEncrypt($dataContent, $privateKey, true);
    }

    /**
     * 私钥解密
     * @param string $encrypted  hex 密文
     * @param string $privateKey 必传私钥,可为 base64(PEM) 或原始 PEM
     * @return string|false 明文;失败 false
     */
    public static function decryptByPrivateKey($encrypted, string $privateKey)
    {
        return self::chunkDecrypt($encrypted, $privateKey, true);
    }

    /**
     * 公钥加密
     * @param string $dataContent 明文
     * @param string $publicKey   必传公钥,可为 base64(PEM) 或原始 PEM
     * @return string|false hex 密文;失败 false
     */
    public static function encryptedByPublicKey($dataContent, string $publicKey)
    {
        return self::chunkEncrypt($dataContent, $publicKey, false);
    }

    /**
     * 公钥解密
     * @param string $encrypted hex 密文
     * @param string $publicKey 必传公钥,可为 base64(PEM) 或原始 PEM
     * @return string|false 明文;失败 false
     */
    public static function decryptByPublicKey($encrypted, string $publicKey)
    {
        return self::chunkDecrypt($encrypted, $publicKey, false);
    }

    /**
     * @param mixed $dataContent 明文内容
     * @param string $key 公钥/私钥 PEM
     * @param bool $usePrivate true=私钥加密;false=公钥加密
     * @return string|false
     */
    private static function chunkEncrypt($dataContent, string $key, bool $usePrivate)
    {
        $key      = self::pem($key, $usePrivate);
        $keyBytes = self::keyBytes($key, $usePrivate);
        if ($keyBytes <= 0) {
            return false;
        }
        //每块取该密钥 PKCS1 明文上限(keyBytes-11),块数最少最省;加密块大小不影响解密
        $chunkLen = $keyBytes - 11;
        if ($chunkLen < 1) {
            return false;
        }

        $dataContent = base64_encode((string) $dataContent);
        $encrypted   = '';
        $total       = strlen($dataContent);
        for ($pos = 0; $pos < $total; $pos += $chunkLen) {
            $chunk = substr($dataContent, $pos, $chunkLen);
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
     * @param string $encrypted hex 密文
     * @param string $key 公钥/私钥 PEM
     * @param bool $usePrivate true=私钥解密;false=公钥解密
     * @return string|false
     */
    private static function chunkDecrypt($encrypted, string $key, bool $usePrivate)
    {
        $key      = self::pem($key, $usePrivate);
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
     * 把各种形态的密钥统一规整成 openssl 可用的标准 PEM。兼容:
     *  1) 完整 PEM(含 -----BEGIN-----/-----END----- 头尾,已断行)→ 原样用；
     *  2) base64(完整 PEM)(两系统间传输的默认形式)→ 解码出 PEM；
     *  3) 仅 base64 内容(无头尾,可能未断行)→ 去空白、按 64 字符断行、补对应头尾,重组标准 PEM。
     * 头尾按公/私钥取 PUBLIC KEY / PRIVATE KEY(现代 SPKI/PKCS8 标签)。
     * @param string $key 原始密钥内容
     * @param bool $usePrivate true=私钥;false=公钥
     */
    private static function pem($key, bool $usePrivate): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return $key;
        }
        //1) 已是完整 PEM
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        //2) base64(完整 PEM):解码后含 BEGIN 头
        $decoded = base64_decode($key, true);
        if ($decoded !== false && str_contains($decoded, '-----BEGIN')) {
            return $decoded;
        }
        //3) 仅 base64 内容(无头,可能未断行):去空白 + 64 字符断行 + 补头尾
        $b64     = preg_replace('/\s+/', '', $key);
        $wrapped = chunk_split($b64, 64, "\n");
        $label   = $usePrivate ? 'PRIVATE KEY' : 'PUBLIC KEY';
        return "-----BEGIN {$label}-----\n{$wrapped}-----END {$label}-----\n";
    }

    /**
     * 由密钥推导其字节数(位数/8),用于密文分块。
     * @param string $key 标准 PEM
     * @param bool $usePrivate true=私钥;false=公钥
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
