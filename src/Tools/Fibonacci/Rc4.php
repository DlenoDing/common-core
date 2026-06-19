<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Fibonacci;

class Rc4
{
    const STATE_SIZE = 256;

    /**
     * RC4 加密
     * 密钥由数据的 MD5 自动派生，密文末尾追加 MD5 供解密还原
     *
     * @param string $data 明文
     * @return string      密文 + 32 字节 MD5 密钥
     */
    public static function encrypt(string $data): string
    {
        $key = md5($data);
        return self::process($key, $data) . $key;
    }

    /**
     * RC4 解密
     *
     * @param string $data 密文（末尾 32 字节为 MD5 密钥）
     * @return string      明文
     */
    public static function decrypt(string $data): string
    {
        $key  = substr($data, -32);
        $data = substr($data, 0, -32);
        return self::process($key, $data);
    }

    /**
     * RC4 核心算法（对称，加解密共用）
     *
     * @param string $key  密钥
     * @param string $data 待处理数据
     * @return string
     */
    private static function process(string $key, string $data): string
    {
        $stateSize = self::STATE_SIZE;
        $pwdLen    = strlen($key);
        $dataLen   = strlen($data);

        // 初始化密钥调度数组
        $keyArr = [];
        $box    = [];
        for ($i = 0; $i < $stateSize; $i++) {
            $keyArr[$i] = ord($key[$i % $pwdLen]);
            $box[$i]    = $i;
        }

        // KSA（密钥调度算法）
        for ($j = $i = 0; $i < $stateSize; $i++) {
            $j       = ($j + $box[$i] + $keyArr[$i]) % $stateSize;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        // PRGA（伪随机生成算法）
        $cipher = '';
        for ($a = $j = $i = 0; $i < $dataLen; $i++) {
            $a       = ($a + 1) % $stateSize;
            $j       = ($j + $box[$a]) % $stateSize;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $k       = $box[($box[$a] + $box[$j]) % $stateSize];
            $cipher  .= chr(ord($data[$i]) ^ $k);
        }

        return $cipher;
    }
}
