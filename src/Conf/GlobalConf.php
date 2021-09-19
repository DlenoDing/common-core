<?php

namespace Dleno\CommonCore\Conf;

/**
 * 系统公共配置
 */
class GlobalConf
{
    //默认空时间格式
    const DEFAULT_DATE_TIME = '0000-00-00 00:00:00';

    //最大时间
    const MAX_TIME = ' 23:59:59';//前面空格保留

    //最小时间
    const MIN_TIME = ' 00:00:00';//前面空格保留

    //默认时间格式
    const DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    //默认时间格式
    const DATE_FORMAT = 'Y-m-d';

    //默认时间格式
    const TIME_FORMAT = 'H:i:s';

    //白名单标识
    const
        WHITE_TYPE_ENCRYPT = 1,//接口加密
        WHITE_TYPE_SIGN = 2,//接口鉴权
        WHITE_TYPE_TOKEN = 4;//用户token
}
