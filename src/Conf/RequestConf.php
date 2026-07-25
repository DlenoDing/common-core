<?php

namespace Dleno\CommonCore\Conf;

/**
 * 当前请求配置
 */
class RequestConf
{
    //是否在http服务内
    const IN_HTTP_SERVER = '__IN_HTTP_SERVER__';
    //请求开始执行时间 KEY
    const REQUEST_RUN_START = '__RUN_START__';
    //请求开始执行占用内存 KEY
    const REQUEST_RUN_MEM = '__RUN_MEM__';
    //请求TRACE ID KEY
    const REQUEST_TRACE_ID = '__TRACE_ID__';
    //请求时区 KEY
    const REQUEST_TIMEZONE = '__TIMEZONE__';
    //请求路由对应的MCA
    const REQUEST_MCA = '__MCA__';
    //请求ReqId
    const REQUEST_REQ_ID = '__REQ_ID__';
    //输出不自动转换
    const OUTPUT_NOT_FORMAT = '__NOT_FORMAT__';
    //html输出
    const OUTPUT_HTML = '__OUTPUT_HTML__';
    //不记录api正常输出日志
    const OUTPUT_NO_LOG = '__OUTPUT_NO_LOG__';
    //输出时间字段转换忽略列表
    const OUTPUT_TIME_CONVERSION_IGNORE_FIELDS = '__OUTPUT_TIME_CONVERSION_IGNORE_FIELDS__';

    //SQL日志模式:null=走全局配置;0=全部记录;1=不记录;2=只记录慢日志
    const LOGGER_NO_SQL = '__LOGGER_NO_SQL__';
    const LOGGER_SQL_MODE_ALL = 0;
    const LOGGER_SQL_MODE_NONE = 1;
    const LOGGER_SQL_MODE_SLOW_ONLY = 2;

    //请求是否admin模块
    const REQUEST_ADMIN_MODULE = '__ADMIN_MODULE__';
    //请求路由白名单值
    const REQUEST_ROUTE_VAL = '__ROUTE_VAL__';
    //请求数据解密AES KEY
    const REQUEST_AES_KEY = '__AES_KEY__';

}
