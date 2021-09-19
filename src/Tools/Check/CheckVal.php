<?php

namespace Dleno\CommonCore\Tools\Check;

/**
 *  公共验证工具
 * @author  dleno
 */
class CheckVal
{

    /**
     * 检查是否包含某位运算值
     * $val 要检查的值
     * $status 检查的目标值
     * */
    public static function checkInStatus($val, $status)
    {
        $check = intval($status & $val);
        if ($check != $val) {
            return false;
        }
        return true;
    }

    /**
     * 判断是否是json格式
     * */
    public static function isJson($string)
    {
        if (!$string || !is_string($string)) {
            return false;
        }
        $string = json_decode(htmlspecialchars_decode($string));
        if (!is_object($string) && !is_array($string)) {
            return false;
        }
        return true;
        //>=php5.3
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * 判断是否是xml格式
     * */
    public static function isXml($string)
    {
        if (!$string || !is_string($string)) {
            return false;
        }
        $xmlParser = xml_parser_create();
        if (!xml_parse($xmlParser, $string, true)) {
            xml_parser_free($xmlParser);
            return false;
        }
        return true;
    }

    /**
     * 车牌号
     * */
    public static function isCarPlate($license)
    {
        if (!$license) {
            return false;
        }
        $license = strtoupper($license);
        $match   = '/^(([京津沪渝冀豫云辽黑湘皖鲁新苏浙赣鄂桂甘晋蒙陕吉闽贵粤青藏川宁琼使领A-Z][A-Z](([0-9]{5}[DF])|' .
                   '([DF]([A-HJ-NP-Z0-9])[0-9]{4})))|' .
                   '([京津沪渝冀豫云辽黑湘皖鲁新苏浙赣鄂桂甘晋蒙陕吉闽贵粤青藏川宁琼使领A-Z][A-Z][A-HJ-NP-Z0-9]{4}' .
                   '[A-HJ-NP-Z0-9挂学警港澳使领]))$/u';
        return preg_match($match, $license) ? true : false;

        #匹配民用车牌和使馆车牌
        $regular = "/^[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新使]{1}[A-Z]{1}[0-9A-Z]{5}$/u";
        preg_match($regular, $license, $match);
        if (isset($match[0])) {
            return true;
        }

        #匹配特种车牌(挂,警,学,领,港,澳)
        $regular = '/^[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新]{1}[A-Z]{1}[0-9A-Z]{4}[挂警学领港澳]{1}$/u';
        preg_match($regular, $license, $match);
        if (isset($match[0])) {
            return true;
        }

        #匹配武警车牌
        $regular = '/^WJ[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新]?[0-9A-Z]{5}$/ui';
        preg_match($regular, $license, $match);
        if (isset($match[0])) {
            return true;
        }

        #匹配军牌
        $regular = "/^[A-Z]{2}[0-9]{5}$/";
        preg_match($regular, $license, $match);
        if (isset($match[0])) {
            return true;
        }

        #小型新能源车
        $regular = "/^[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新]{1}[A-Z]{1}[DF]{1}[0-9A-Z]{5}$/u";
        preg_match($regular, $license, $match);
        if (isset($match[0])) {
            return true;
        }

        #大型新能源车
        $regular = "/^[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新]{1}[A-Z]{1}[0-9A-Z]{5}[DF]{1}$/u";
        preg_match($regular, $license, $match);
        if (isset($match[0])) {
            return true;
        }
        return false;
    }

    /**
     * 整数（正负数）
     */
    public static function isInteger($str)
    {
        if (!is_numeric($str)) {
            return false;
        }
        return preg_match('/^(\-)?[0-9]+$/', $str) ? true : false;
    }

    /**
     * 正整数
     */
    public static function isPosInt($str)
    {
        if (!is_numeric($str)) {
            return false;
        }
        return preg_match('/^[0-9]+$/', $str) ? true : false;
    }

    /**
     * 负整数
     */
    public static function isNegInt($str)
    {
        if (!is_numeric($str)) {
            return false;
        }
        return preg_match('/^\-[0-9]+$/', $str) ? true : false;
    }

    /**
     * 浮点数
     */
    public static function isFloat($str)
    {
        if (!is_numeric($str)) {
            return false;
        }
        return preg_match('/^[0-9]+\.[0-9]+$/', $str) ? true : false;
    }

    /**
     * 正则表达式验证email格式
     */
    public static function isEmail($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        return preg_match('#[a-z0-9&\-_.]+@[\w\-_]+([\w\-.]+)?\.[\w\-]+#is', $str) ? true : false;
    }

    /**
     * 正则表达式验证正常格式字符串；数字字母下划线
     */
    public static function isValid($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9_]+$/', $str) ? true : false;
    }

    /**
     * 正则表达式验证网址
     */
    public static function isUrl($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        return preg_match(
            '#^http(s)?://([a-zA-Z0-9\.]?([0-9A-Za-z][0-9A-Za-z-]+\.)+[A-Za-z]{2,5})|((25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9])\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[0-9]))#',
            $str
        ) ? true : false;
    }

    /**
     * 验证字符串中是否含有汉字
     */
    public static function isChineseCharacter($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        return preg_match('~[\x{4e00}-\x{9fa5}]+~u', $str) ? true : false;
    }

    /**
     * 验证字符串中是否含有非法字符
     */
    public static function isInvalidStr($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        return preg_match('#[!\#$%^&*(){}~`"\';:?+=<>/\[\]]+#', $str, $arr) ? true : false;
    }

    /**
     * 用正则表达式验证邮证编码
     */
    public static function isPostNum($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        return preg_match('#^[1-9][0-9]{5}$#', $str) ? true : false;
    }

    /**
     * 用正则表达式验证是否日期
     */
    public static function isDate($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        $reg = '/^((((1[6-9]|[2-9][0-9])[0-9]{2})-(0?[13578]|1[02])-(0?[1-9]|[12][0-9]|3[01]))|(((1[6-9]|[2-9][0-9])[0-9]{2})-(0?[13456789]|1[012])-(0?[1-9]|[12][0-9]|30))|(((1[6-9]|[2-9][0-9])[0-9]{2})-0?2-(0?[1-9]|1[0-9]|2[0-9]))|(((1[6-9]|[2-9][0-9])(0[48]|[2468][048]|[13579][26])|((16|[2468][048]|[3579][26])00))-0?2-29-))$/';
        return preg_match($reg, $str) ? true : false;
    }

    /**
     * 用正则表达式验证是否日期时间
     */
    public static function isDateTime($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        $reg = '/^((((1[6-9]|[2-9][0-9])[0-9]{2})-(0?[13578]|1[02])-(0?[1-9]|[12][0-9]|3[01]))|(((1[6-9]|[2-9][0-9])[0-9]{2})-(0?[13456789]|1[012])-(0?[1-9]|[12][0-9]|30))|(((1[6-9]|[2-9][0-9])[0-9]{2})-0?2-(0?[1-9]|1[0-9]|2[0-9]))|(((1[6-9]|[2-9][0-9])(0[48]|[2468][048]|[13579][26])|((16|[2468][048]|[3579][26])00))-0?2-29-)) (20|21|22|23|[0-1]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]$/';
        return preg_match($reg, $str) ? true : false;
    }

    /**
     * 用正则表达式验证是否星期值
     */
    public static function isWeek($str)
    {
        if (!is_numeric($str)) {
            return false;
        }
        $reg = '/^[0-6]$/';
        return preg_match($reg, $str) ? true : false;
    }

    /**
     * 用正则表达式验证是否小时
     */
    public static function isHours($str)
    {
        if (!is_numeric($str)) {
            return false;
        }
        $reg = '/^(20|21|22|23|24|[0-1]?[0-9])$/';
        return preg_match($reg, $str) ? true : false;
    }

    /**
     * 用正则表达式验证是否时间-完整格式
     */
    public static function isTime($str)
    {
        if (!is_string($str)) {
            return false;
        }
        $reg = '/^(20|21|22|23|[0-1]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]$/';
        return preg_match($reg, $str) ? true : false;
    }

    /**
     * 用正则表达式验证是否时间-到分
     */
    public static function isTimeMinute($str)
    {
        if (!is_string($str)) {
            return false;
        }
        $reg = '/^(20|21|22|23|[0-1]?[0-9]):[0-5]?[0-9]$/';
        return preg_match($reg, $str) ? true : false;
    }


    /**
     * 正则表达式验证IP地址, 注:仅限IPv4
     */
    public static function isIp($str)
    {
        if (!$str || !is_string($str)) {
            return false;
        }
        if (!preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', $str)) {
            return false;
        }
        $ipArray = explode('.', $str);
        //真实的ip地址每个数字不能大于255（0-255）
        return ($ipArray[0] <= 255 && $ipArray[1] <= 255 && $ipArray[2] <= 255 && $ipArray[3] <= 255) ? true : false;
    }

    /**
     * 用正则表达式验证手机号码(中国大陆区)
     */
    public static function isMobile($str)
    {
        if (!$str) {
            return false;
        }
        return preg_match('#^1[3456789]\d{9}$#', $str) ? true : false;
    }

    /**
     * 手机号和座机号的验证(中国大陆区)
     */
    public static function isPhoneNum($str)
    {
        if (!is_string($str)) {
            return false;
        }
        $patter = "#(^(0[0-9]{2,3}-?)?([2-9][0-9]{6,7})+(-?[0-9]{1,4})?)|(^1[3456789]\d{9})$#";
        return preg_match($patter, $str) ? true : false;
    }

    /**
     * 手机号和座机号(不带-)的验证(中国大陆区)400免费电话
     */
    public static function isPhoneNumber($str)
    {
        if (!is_string($str)) {
            return false;
        }
        $patter = "#(^(0[0-9]{2})([2-9][0-9]{7}))$|(^(0[0-9]{3})([2-9][0-9]{6,7}))$|(^1[3456789]\d{9})$|(^400[0-9]{7})$#";
        return preg_match($patter, $str) ? true : false;
    }

    /**
     * 检查字符串长度
     */
    public static function isLength($string = null, $min = 0, $max = 255)
    {
        //参数分析
        if (is_null($string) || !is_string($string)) {
            return false;
        }
        //获取字符串长度
        $length = strlen(trim($string));
        if ($min > 0 && $max > 0) {
            return (($length >= (int)$min) && ($length <= (int)$max)) ? true : false;
        } elseif ($min > 0 && $max == 0) {
            return ($length >= (int)$min) ? true : false;
        } elseif ($max > 0 && $min == 0) {
            return ($length <= (int)$max) ? true : false;
        }
    }

    /**
     * 检查值的最大最小,数字与日期及字符串
     *
     */
    public static function checkMinMax($str, $min = null, $max = null)
    {
        if (is_null($min) && is_null($max)) {
            return false;
        }
        if (!is_null($min) && !is_null($max)) {
            return ($str >= $min && $str <= $max) ? true : false;
        } elseif (!is_null($min) && is_null($max)) {
            return ($str >= $min) ? true : false;
        } elseif (is_null($min) && !is_null($max)) {
            return ($str <= $max) ? true : false;
        }
        return false;
    }

    /**
     * 验证身份证的有效性
     */
    public static function isIdCard($idcard)
    {
        if (!$idcard || !is_string($idcard)) {
            return false;
        }
        // 只能是18位
        if (strlen($idcard) != 18) {
            return false;
        }
        // 取出本体码
        $idcard_base = substr($idcard, 0, 17);
        //主码只能全是数字
        if (!self::isPosInt($idcard_base)) {
            return false;
        }
        // 取出校验码
        $verify_code = strtoupper(substr($idcard, 17, 1));
        // 校验码对应值
        $verify_code_list = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        //尾值只能在$verify_code_list中
        if (!in_array($verify_code, $verify_code_list)) {
            return false;
        }
        /*
        // 加权因子  --  屏蔽此项判断，部分身份证号码没有根据该规则计算
        $factor = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        // 根据前17位计算校验码
        $total = 0;
        for ($i=0; $i<17; $i++) {
            $total += substr($idcard_base, $i, 1)*$factor[$i];
        }
        // 取模
        $mod = $total % 11;
        // 比较校验码
        if ($verify_code == $verify_code_list[$mod]) {
            return true;
        } else {
            return false;
        }
        */
        //生日校验
        $birth = [];
        $birth[] = substr($idcard, 6, 4);
        $birth[] = substr($idcard, 10, 2);
        $birth[] = substr($idcard, 12, 2);
        $birth = join('-', $birth);
        if (!self::isDate($birth)) {
            return false;
        }

        return true;
    }

    /**
     * 检查是否是html颜色代码
     */
    public static function isHtmlColor($colorCode)
    {
        if (!$colorCode || !is_string($colorCode)) {
            return false;
        }
        return preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $colorCode) ? true : false;
    }

    /**
     * 检查utf8字符长度 （中文）
     */
    public static function utf8StringMinMax($str, $min, $max)
    {
        if (!$str || !is_string($str)) {
            return false;
        }

        $len = mb_strlen($str, "utf8");
        if (!is_null($min) && !is_null($max) && ($len < $min || $len > $max)) {
            return false;
        } elseif (!is_null($min) && $len < $min) {
            return false;
        } elseif (!is_null($max) && $len > $max) {
            return false;
        }

        return true;
    }
}
