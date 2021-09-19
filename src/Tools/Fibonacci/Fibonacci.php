<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Fibonacci;

class Fibonacci
{
    const HEART_FREQUENCY = 55; //请求次数

    /**
     * 菲波拉契数解密
     * Xtea和Rc4解密
     *
     * @param string $requestData 加密字符串
     * @return array
     */
    public static function recvData(string $requestData)
    {
        $base64_decode = base64_decode($requestData);
        $bin2hex       = ($base64_decode);
        $heart         = '' . substr($bin2hex, 0, 8);
        $normalData    = '' . substr($bin2hex, 8, strlen($bin2hex));
        try {
            if (self::isFibonacci(hexdec($heart)) && hexdec($heart) > self::HEART_FREQUENCY) {
                $data   = $normalData;
                $deData = Rc4::derc4($data);
            } else {
                $keys_binary  = random_bytes(4 * 4);
                $keys_array   = Xtea::binary_key_to_int_array($keys_binary);
                $data         = ($normalData);
                $string2Bytes = self::string2Bytes($data);
                $deData       = Xtea::decrypt($string2Bytes, $keys_array);
            }
            $recvData['heart'] = hexdec($heart);
            $recvData['data']  = $deData;
            return $recvData;
        } catch (\Throwable $e) {
            throw new \Exception('解密出错:' . $e->getMessage());
        }
    }


    /**
     * 判断一个数是不是菲波拉契数
     *
     * @param [type] $num
     * @return boolean
     */
    private static function isFibonacci($num)
    {
        $i = 1;
        $j = 1;
        $n = 2;
        while ($n < $num) {
            $n = $i + $j;
            $i = $j;
            $j = $n;
        }
        if ($n == $num) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * Undocumented function
     *
     * @param string $data
     * @return string
     */
    private static function string2Bytes(string $data)
    {
        $ret = '';
        for ($i = 0; $i < strlen($data); $i += 2) {
            $temp = substr($data, $i, 2);
            $ret  .= $temp;
        }
        return hex2bin($ret);
    }
}
