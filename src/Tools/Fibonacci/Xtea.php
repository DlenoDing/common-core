<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Fibonacci;

class Xtea
{
    const PAD_NONE   = 0;
    const PAD_0x00   = 1;
    const PAD_RANDOM = 2;

    /**
     * 将二进制字符串转换为 XTEA 密钥数组（4个 uint32）
     *
     * @param string $key            16 字节二进制密钥
     * @param int    $paddingScheme  PAD_NONE 或 PAD_0x00
     * @return int[]
     */
    public static function binaryKeyToIntArray(string $key, int $paddingScheme = self::PAD_0x00): array
    {
        if ($paddingScheme !== self::PAD_0x00 && $paddingScheme !== self::PAD_NONE) {
            throw new \InvalidArgumentException('only PAD_NONE and PAD_0x00 is supported!');
        }
        $len = strlen($key);
        if ($len > 16) {
            throw new \InvalidArgumentException('the max length for a XTEA binary key is 16 bytes.');
        } elseif ($paddingScheme === self::PAD_NONE && $len !== 16) {
            throw new \InvalidArgumentException('with PAD_NONE the key has to be _EXACTLY_ 16 bytes long.');
        } elseif ($len < 16) {
            $key .= str_repeat("\x00", 16 - $len);
        }

        $ret = [];
        foreach (str_split($key, 4) as $chunk) {
            $ret[] = self::fromLittleUint32($chunk);
        }
        assert(count($ret) === 4);

        return $ret;
    }

    /**
     * XTEA 加密
     *
     * @param string $data
     * @param int[]  $keys           4 个 uint32
     * @param int    $paddingScheme
     * @param int    $rounds
     * @return string
     */
    public static function encrypt(
        string $data,
        array $keys,
        int $paddingScheme = self::PAD_0x00,
        int $rounds = 32
    ): string {
        if ($paddingScheme < 0 || $paddingScheme > 2) {
            throw new \InvalidArgumentException('only PAD_NONE and PAD_0x00 and PAD_RANDOM supported!');
        }
        if (count($keys) !== 4) {
            throw new \InvalidArgumentException('count($keys) !== 4');
        }
        for ($i = 0; $i < 4; ++$i) {
            if (!is_int($keys[$i])) {
                throw new \InvalidArgumentException('!is_int($keys[' . $i . '])');
            }
            if ($keys[$i] < 0) {
                throw new \InvalidArgumentException('$keys[' . $i . '] < 0');
            }
            if ($keys[$i] > 0xFFFFFFFF) {
                throw new \InvalidArgumentException('$keys[' . $i . '] > 0xFFFFFFFF');
            }
        }
        if ($rounds < 0) {
            throw new \InvalidArgumentException('rounds < 0 is impossible (and <32 is probably a bad idea)');
        }
        $len = strlen($data);
        if ($len === 0 || ($len % 8) !== 0) {
            if ($paddingScheme === self::PAD_NONE) {
                throw new \InvalidArgumentException('with PAD_NONE the data MUST be a multiple of 8 bytes!');
            }
        }

        return self::encryptUnsafe($data, $keys, $paddingScheme, $rounds);
    }

    /**
     * XTEA 加密（跳过输入校验，性能更高）
     *
     * @param string $data
     * @param int[]  $keys
     * @param int    $paddingScheme
     * @param int    $rounds
     * @return string
     */
    public static function encryptUnsafe(
        string $data,
        array $keys,
        int $paddingScheme = self::PAD_0x00,
        int $rounds = 32
    ): string {
        $len = strlen($data);
        if ($len === 0) {
            $len = 8;
            if ($paddingScheme === self::PAD_0x00) {
                $data = str_repeat("\x00", 8);
            } else {
                $data = random_bytes(8);
            }
        } elseif (($len % 8) !== 0) {
            $nearest = (int)(ceil($len / 8) * 8);
            if ($paddingScheme === self::PAD_0x00) {
                $data .= str_repeat("\x00", $nearest - $len);
            } else {
                $data .= random_bytes($nearest - $len);
            }
            $len = $nearest;
        }

        $ret = '';
        for ($i = 0; $i < $len; $i += 8) {
            $i1 = self::fromLittleUint32(substr($data, $i, 4));
            $i2 = self::fromLittleUint32(substr($data, $i + 4, 4));
            self::encipherUnsafe($i1, $i2, $keys, $rounds);
            $ret .= self::toLittleUint32($i1);
            $ret .= self::toLittleUint32($i2);
        }

        return $ret;
    }

    /**
     * XTEA 解密
     *
     * @param string $data
     * @param int[]  $keys
     * @param int    $rounds
     * @return string
     */
    public static function decrypt(string $data, array $keys, int $rounds = 32): string
    {
        $len = strlen($data);
        if ($len < 8) {
            throw new \InvalidArgumentException(
                'this cannot be (intact) xtea-encrypted data, it\'s less than 8 bytes long'
            );
        }
        if (($len % 8) !== 0) {
            throw new \InvalidArgumentException(
                'this cannot be (intact) xtea-encrypted data, the length is not a multiple of 8 bytes.'
            );
        }
        if (count($keys) !== 4) {
            throw new \InvalidArgumentException('count($keys) !== 4');
        }
        for ($i = 0; $i < 4; ++$i) {
            if (!is_int($keys[$i])) {
                throw new \InvalidArgumentException('!is_int($keys[' . $i . '])');
            }
            if ($keys[$i] < 0) {
                throw new \InvalidArgumentException('$keys[' . $i . '] < 0');
            }
            if ($keys[$i] > 0xFFFFFFFF) {
                throw new \InvalidArgumentException('$keys[' . $i . '] > 0xFFFFFFFF');
            }
        }
        if ($rounds < 0) {
            throw new \InvalidArgumentException('rounds < 0 is impossible (and <32 is probably a bad idea)');
        }

        return self::decryptUnsafe($data, $keys, $rounds);
    }

    /**
     * XTEA 解密（跳过输入校验，性能更高）
     *
     * @param string $data
     * @param int[]  $keys
     * @param int    $rounds
     * @return string
     */
    public static function decryptUnsafe(string $data, array $keys, int $rounds = 32): string
    {
        $ret = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 8) {
            $i1 = self::fromLittleUint32(substr($data, $i, 4));
            $i2 = self::fromLittleUint32(substr($data, $i + 4, 4));
            self::decipherUnsafe($i1, $i2, $keys, $rounds);
            $ret .= self::toLittleUint32($i1);
            $ret .= self::toLittleUint32($i2);
        }

        return $ret;
    }

    // ─── 内部方法 ──────────────────────────────────────────

    protected static function fromLittleUint32(string $i): int
    {
        $arr = unpack('Vuint32', $i);
        return $arr['uint32'];
    }

    protected static function toLittleUint32(int $i): string
    {
        return pack('V', $i);
    }

    protected static function encipher(int &$data1, int &$data2, array $keys, int $rounds): void
    {
        if ($data1 < 0 || $data2 < 0 || $data1 > 0xFFFFFFFF || $data2 > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('data values out of uint32 range');
        }
        if (count($keys) !== 4) {
            throw new \InvalidArgumentException('count($keys) !== 4');
        }
        for ($i = 0; $i < 4; ++$i) {
            if (!is_int($keys[$i]) || $keys[$i] < 0 || $keys[$i] > 0xFFFFFFFF) {
                throw new \InvalidArgumentException('key[' . $i . '] is invalid');
            }
        }
        self::encipherUnsafe($data1, $data2, $keys, $rounds);
    }

    protected static function encipherUnsafe(int &$data1, int &$data2, array $keys, int $rounds): void
    {
        $sum = 0;
        for ($i = 0; $i < $rounds; ++$i) {
            $data1 = self::add(
                $data1,
                self::add($data2 << 4 ^ self::rshift($data2, 5), $data2) ^
                self::add($sum, $keys[$sum & 3])
            );
            $sum   = self::add($sum, 0x9e3779b9);
            $data2 = self::add(
                $data2,
                self::add($data1 << 4 ^ self::rshift($data1, 5), $data1) ^
                self::add($sum, $keys[self::rshift($sum, 11) & 3])
            );
        }
        $data1 = (int)$data1;
        $data2 = (int)$data2;
    }

    protected static function decipherUnsafe(int &$data1, int &$data2, array $keys, int $rounds): void
    {
        $sum = self::add(0, 0x9E3779B9 * $rounds);
        for ($i = 0; $i < $rounds; ++$i) {
            $data2 = self::add(
                $data2,
                -(self::add($data1 << 4 ^ self::rshift($data1, 5), $data1) ^
                  self::add($sum, $keys[self::rshift($sum, 11) & 3]))
            );
            $sum   = self::add($sum, -(0x9E3779B9));
            $data1 = self::add(
                $data1,
                -(self::add($data2 << 4 ^ self::rshift($data2, 5), $data2) ^
                  self::add($sum, $keys[$sum & 3]))
            );
        }
        $data1 = (int)$data1;
        $data2 = (int)$data2;
    }

    /**
     * 无符号右移（处理 PHP 有符号位移）
     * @see https://github.com/pear/Crypt_Xtea/blob/trunk/Xtea.php
     */
    protected static function rshift(int|float $integer, int $n): int|float
    {
        if (0xffffffff < $integer || -0xffffffff > $integer) {
            $integer = fmod($integer, 0xffffffff + 1);
        }
        if (0x7fffffff < $integer) {
            $integer -= 0xffffffff + 1.0;
        } elseif (-0x80000000 > $integer) {
            $integer += 0xffffffff + 1.0;
        }
        if (0 > $integer) {
            $integer &= 0x7fffffff;
            $integer >>= $n;
            $integer |= 1 << (31 - $n);
        } else {
            $integer >>= $n;
        }

        return $integer;
    }

    /**
     * 无符号加法（处理 PHP 有符号溢出）
     * @see https://github.com/pear/Crypt_Xtea/blob/trunk/Xtea.php
     */
    protected static function add(int|float $i1, int|float $i2): int|float
    {
        $result = 0.0;
        foreach ([$i1, $i2] as $value) {
            if (0.0 > $value) {
                $value -= 1.0 + 0xffffffff;
            }
            $result += $value;
        }
        if (0xffffffff < $result || -0xffffffff > $result) {
            $result = fmod($result, 0xffffffff + 1);
        }
        if (0x7fffffff < $result) {
            $result -= 0xffffffff + 1.0;
        } elseif (-0x80000000 > $result) {
            $result += 0xffffffff + 1.0;
        }

        return $result;
    }
}
