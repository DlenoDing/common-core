<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Crypt;

/**
 * 通用 RSA 分块加解密工具（变体）。
 *
 * 与 {@see OpenSslRsa} 的区别：明文**原样分块**(不先 base64)、密文以 **base64** 整体输出(非 hex)。
 * 其余一致：密钥由调用方传入(统一 **base64(PEM)**,也容错原始 PEM / 无头尾的裸 base64);
 * 本类不内置默认密钥、不读 config；分块按"密钥位数"自适应(兼容 1024/2048/4096);
 * openssl 调用失败返回 false(不再静默拼出垃圾)。
 */
class OpenSslRsa2
{
    /** 私钥加密 → base64 密文;失败 false */
    public static function encryptedByPrivateKey($dataContent, string $privateKey)
    {
        return self::chunkEncrypt($dataContent, $privateKey, true);
    }

    /** 私钥解密 → 明文;失败 false */
    public static function decryptByPrivateKey($encrypted, string $privateKey)
    {
        return self::chunkDecrypt($encrypted, $privateKey, true);
    }

    /** 公钥加密 → base64 密文;失败 false */
    public static function encryptedByPublicKey($dataContent, string $publicKey)
    {
        return self::chunkEncrypt($dataContent, $publicKey, false);
    }

    /** 公钥解密 → 明文;失败 false */
    public static function decryptByPublicKey($encrypted, string $publicKey)
    {
        return self::chunkDecrypt($encrypted, $publicKey, false);
    }

    /**
     * @param bool $usePrivate true=私钥加密;false=公钥加密
     * @return string|false base64 密文
     */
    private static function chunkEncrypt($dataContent, string $key, bool $usePrivate)
    {
        $key      = self::pem($key, $usePrivate);
        $keyBytes = self::keyBytes($key, $usePrivate);
        if ($keyBytes <= 0) {
            return false;
        }
        $chunk     = $keyBytes - 11;          //PKCS1 v1.5 单块最大明文 = 密钥字节数 - 11
        $encrypted = '';
        $total     = strlen((string) $dataContent);
        for ($pos = 0; $pos < $total; $pos += $chunk) {
            $part = substr((string) $dataContent, $pos, $chunk);
            $ok   = $usePrivate
                ? openssl_private_encrypt($part, $out, $key)
                : openssl_public_encrypt($part, $out, $key);
            if (!$ok) {
                return false;
            }
            $encrypted .= $out;
        }
        return base64_encode($encrypted);
    }

    /**
     * @param bool $usePrivate true=私钥解密;false=公钥解密
     * @return string|false 明文
     */
    private static function chunkDecrypt($encrypted, string $key, bool $usePrivate)
    {
        $key      = self::pem($key, $usePrivate);
        $keyBytes = self::keyBytes($key, $usePrivate);
        if ($keyBytes <= 0) {
            return false;
        }
        //去空白后严格 base64 解码:非法字符 → false(与 OpenSslRsa 的非法即 false 一致)
        $enc = preg_replace('/\s+/', '', (string) $encrypted);
        $bin = base64_decode($enc, true);
        if ($bin === false) {
            return false;
        }
        $decrypt = '';
        $total   = strlen($bin);
        for ($pos = 0; $pos < $total; $pos += $keyBytes) {   //密文一块 = 密钥字节数
            $block = substr($bin, $pos, $keyBytes);
            $ok    = $usePrivate
                ? openssl_private_decrypt($block, $out, $key)
                : openssl_public_decrypt($block, $out, $key);
            if (!$ok) {
                return false;
            }
            $decrypt .= $out;
        }
        return $decrypt;
    }

    /**
     * 把各种形态的密钥统一规整成 openssl 可用的标准 PEM。兼容:
     *  1) 完整 PEM(含 -----BEGIN-----/-----END----- 头尾)→ 原样用；
     *  2) base64(完整 PEM)→ 解码出 PEM；
     *  3) 仅 base64 内容(无头尾,可能未断行)→ 去空白 + 64 字符断行 + 按公/私钥补头尾,重组标准 PEM。
     */
    private static function pem($key, bool $usePrivate): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        $decoded = base64_decode($key, true);
        if ($decoded !== false && str_contains($decoded, '-----BEGIN')) {
            return $decoded;
        }
        $b64     = preg_replace('/\s+/', '', $key);
        $wrapped = chunk_split($b64, 64, "\n");
        $label   = $usePrivate ? 'PRIVATE KEY' : 'PUBLIC KEY';
        return "-----BEGIN {$label}-----\n{$wrapped}-----END {$label}-----\n";
    }

    /**
     * 由密钥推导其字节数(位数/8),用于分块。
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
