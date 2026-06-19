<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Fibonacci;

class Fibonacci
{
    const HEART_FREQUENCY = 55; // 心跳判定阈值（请求次数）

    /**
     * XTEA 协议固定密钥（与客户端约定，双端一致）
     * 格式：4 个 uint32
     */
    const XTEA_KEYS = [0x789f5645, 0xf68bd5a4, 0x81963ffa, 0xabcdef12];

    /**
     * RC4 加密时使用的 heart 值（菲波拉契数且 > HEART_FREQUENCY）
     */
    const HEART_RC4  = 89;

    /**
     * XTEA 加密时使用的 heart 值（不触发 RC4 分支）
     */
    const HEART_XTEA = 1;

    /**
     * 菲波拉契协议加密
     * 输出结构：base64( heart(8字节hex) + data(hex) )
     * - $useRc4 = true ：使用 RC4 加密（heart 取 HEART_RC4）
     * - $useRc4 = false：使用 XTEA 加密（heart 取 HEART_XTEA）
     *
     * @param string     $data      明文数据
     * @param bool       $useRc4    是否使用 RC4；默认 false（XTEA）
     * @param int[]|null $xteaKeys  XTEA 密钥数组（4个uint32），不传则使用默认协议密钥
     * @return string               base64 编码的密文
     */
    public static function encrypt(string $data, bool $useRc4 = false, ?array $xteaKeys = null): string
    {
        $xteaKeys = $xteaKeys ?? self::XTEA_KEYS;

        try {
            if ($useRc4) {
                $heartVal   = self::HEART_RC4;
                $payloadHex = bin2hex(Rc4::encrypt($data));
            } else {
                $heartVal   = self::HEART_XTEA;
                $payloadHex = bin2hex(Xtea::encrypt($data, $xteaKeys));
            }

            return base64_encode(sprintf('%08x', $heartVal) . $payloadHex);
        } catch (\Throwable $e) {
            throw new \Exception('加密出错: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 菲波拉契协议解密
     * 输入结构：base64( heart(8字节hex) + data(hex) )
     * - heart 为菲波拉契数且 > HEART_FREQUENCY 时：使用 RC4 解密
     * - 否则：使用 XTEA 解密
     *
     * @param string     $cipherData  base64 编码的密文
     * @param int[]|null $xteaKeys    XTEA 密钥数组（4个uint32），不传则使用默认协议密钥
     * @return array ['heart' => int, 'data' => string]
     */
    public static function decrypt(string $cipherData, ?array $xteaKeys = null): array
    {
        $xteaKeys   = $xteaKeys ?? self::XTEA_KEYS;
        $rawData    = base64_decode($cipherData);
        $heartHex   = substr($rawData, 0, 8);
        $payloadHex = substr($rawData, 8);
        $heartVal   = hexdec($heartHex);

        try {
            if (self::isFibonacci($heartVal) && $heartVal > self::HEART_FREQUENCY) {
                $decrypted = Rc4::decrypt(hex2bin($payloadHex));
            } else {
                $decrypted = Xtea::decrypt(hex2bin($payloadHex), $xteaKeys);
            }

            return [
                'heart' => $heartVal,
                'data'  => $decrypted,
            ];
        } catch (\Throwable $e) {
            throw new \Exception('解密出错: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 判断一个数是否为菲波拉契数
     *
     * @param int|float $num
     * @return bool
     */
    private static function isFibonacci(int|float $num): bool
    {
        if ($num <= 0) {
            return false;
        }
        if ($num === 1) {
            return true;
        }
        $i = 1;
        $j = 1;
        $n = 2;
        while ($n < $num) {
            $n = $i + $j;
            $i = $j;
            $j = $n;
        }

        return $n === $num;
    }
}
