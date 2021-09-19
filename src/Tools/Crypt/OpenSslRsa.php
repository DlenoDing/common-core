<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Crypt;

class OpenSslRsa
{
    const ENCRYPT_LEN = 32;

    public static $publicKey = '-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCnc2SqL+oj590/uZkvuk3Cbrru
ezbDrTQmd8yu5+atQL5ZGUO4OZ5jJPyyFxAZvLKyqNQQYuf8b4FiOfhnnsLbUUUg
o8a0OIjVVVh7G4IRG6YiKrCgvJHsKHgUMRkHxQ0KEfWDJxCN+je3L1WIOtHQeqqW
WtFf/qzoGYkvyijS/wIDAQAB
-----END PUBLIC KEY-----';

    public static $privateKey = '-----BEGIN PRIVATE KEY-----
MIICdwIBADANBgkqhkiG9w0BAQEFAASCAmEwggJdAgEAAoGBAKdzZKov6iPn3T+5
mS+6TcJuuu57NsOtNCZ3zK7n5q1AvlkZQ7g5nmMk/LIXEBm8srKo1BBi5/xvgWI5
+GeewttRRSCjxrQ4iNVVWHsbghEbpiIqsKC8kewoeBQxGQfFDQoR9YMnEI36N7cv
VYg60dB6qpZa0V/+rOgZiS/KKNL/AgMBAAECgYB8UZ+a+pfKsIoClbi1RowUnkEK
bU/rVtww8yBzepg4aKjpXWh5jc2Zrgwt7BF4CjBhlBZdVBEHyYE1e/SAec4P8f4+
hHGvjFP9WhdbpAxSCwNvv17UxNL7QasJesbWhz85hg4sZ4wuAQ090Le3HVREQF7t
5SQV+f2dPoqgSNdbAQJBANulpiZlY2qmG40o5xogVjhTYF1rhxiUv0fQKFUDku8l
ezIbo2ZSIhbYzoyJgYbUai0FdbVLbl5dhu1ptxRS/0kCQQDDKjWHpj2JbSfRrHmu
Ks+O8XggiMLpvFqmCBVqWePh/yAP4QRCFnKNnQqoEkcTj8kGfmmcluPqHpmCyjNT
TRgHAkEAnPo4UrynXrM0gaA3+m4d8Md12Y5d0O2N/07/ZDLXsl7BO0CReTE998If
bEVh8vCgqWh7hYRRbtO8+LRTCg1/MQJAN9tryLAuqpeALwWDKfL8xrebnwwlZQpQ
k3Z60p55l2QShBjtxBByps9Mjn/0sceUTHR/u56ACrDJVOKUQAIvnwJBAJXcfR++
I8W9NStrSg/P/Dqk4yOJ3DhVUzU8G4qtLwVNWH0a1E15YDvhnl5ToqNkiDIEqfbf
5E8qiViuModOpaA=
-----END PRIVATE KEY-----';

    /**
     * 私钥加密
     * @param $dataContent
     * @param $privateKey
     * @return string
     */
    public static function encryptedByPrivateKey($dataContent, $privateKey = null)
    {
        $privateKey  = $privateKey ?: self::$privateKey;
        $dataContent = base64_encode($dataContent);
        $encrypted   = "";
        $totalLen    = strlen($dataContent);
        $encryptPos  = 0;
        while ($encryptPos < $totalLen) {
            openssl_private_encrypt(substr($dataContent, $encryptPos, self::ENCRYPT_LEN), $encryptData, $privateKey);
            $encrypted  .= bin2hex($encryptData);
            $encryptPos += self::ENCRYPT_LEN;
        }
        return $encrypted;
    }

    /**
     * 私钥解密
     * @param $encrypted
     * @return bool|false|string
     */
    public static function decryptByPrivateKey($encrypted, $privateKey = null)
    {
        $privateKey = $privateKey ?: self::$privateKey;
        $decrypt    = "";
        $totalLen   = strlen($encrypted);
        $decryptPos = 0;
        while ($decryptPos < $totalLen) {
            openssl_private_decrypt(
                hex2bin(substr($encrypted, $decryptPos, self::ENCRYPT_LEN * 8)),
                $decryptData,
                $privateKey
            );
            $decrypt    .= $decryptData;
            $decryptPos += self::ENCRYPT_LEN * 8;
        }
        $decrypt = base64_decode($decrypt);
        return $decrypt;
    }

    /**
     * 公钥加密
     * @param $dataContent
     * @return string
     */
    public static function encryptedByPublicKey($dataContent, $publicKey = null)
    {
        $publicKey   = $publicKey ?: self::$publicKey;
        $dataContent = base64_encode($dataContent);
        $encrypted   = "";
        $totalLen    = strlen($dataContent);
        $encryptPos  = 0;
        while ($encryptPos < $totalLen) {
            openssl_public_encrypt(substr($dataContent, $encryptPos, self::ENCRYPT_LEN), $encryptData, $publicKey);
            $encrypted  .= bin2hex($encryptData);
            $encryptPos += self::ENCRYPT_LEN;
        }
        return $encrypted;
    }

    /**
     * 公钥解密
     * @param $encrypted
     * @param $publicKey
     * @return bool|false|string
     */
    public static function decryptByPublicKey($encrypted, $publicKey = null)
    {
        $publicKey  = $publicKey ?: self::$publicKey;
        $decrypt    = "";
        $totalLen   = strlen($encrypted);
        $decryptPos = 0;
        while ($decryptPos < $totalLen) {
            openssl_public_decrypt(
                hex2bin(substr($encrypted, $decryptPos, self::ENCRYPT_LEN * 8)),
                $decryptData,
                $publicKey
            );
            $decrypt    .= $decryptData;
            $decryptPos += self::ENCRYPT_LEN * 8;
        }
        //openssl_public_decrypt($encrypted, $decryptData, $publicKey);
        $decrypt = base64_decode($decrypt);
        return $decrypt;
    }
}
