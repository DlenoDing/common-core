<?php

namespace Dleno\CommonCore\Tools\DateTimes;

use Dleno\CommonCore\Conf\GlobalConf;

class TimeTool
{
    /**
     * 将秒数转换为文字说明时间
     * */
    public static function secondToString($second)
    {
        $str = [];

        $hours = intval($second / 3600);
        if ($hours > 0) {
            $str['hours'] = $hours;
        }

        $minutes = intval($second % 3600 / 60);
        if ($minutes > 0) {
            $str['minutes'] = $minutes;
        }

        $seconds = $second % 60;
        if ($seconds > 0) {
            $str['seconds'] = $seconds;
        }

        if (empty($str)) {
            $str['seconds'] = 0;
        }

        return $str;
    }

    /**
     * @param $dateTime
     * @return false|int
     */
    public static function timestamp($dateTime)
    {
        if (empty($dateTime) || $dateTime == GlobalConf::DEFAULT_DATE_TIME) {
            return 0;
        }
        return strtotime($dateTime);
    }

    /**
     * 获取当前到今日结束为止的秒数
     * @return int
     */
    public static function getDayDoneSecond($date = null)
    {
        if (empty($date)) {
            $date = date('Y-m-d');
        }
        $dayDoneSecond = strtotime('+1 day', strtotime($date)) - time();
        $dayDoneSecond = $dayDoneSecond > 0 ? $dayDoneSecond : 0;

        return $dayDoneSecond;
    }
}