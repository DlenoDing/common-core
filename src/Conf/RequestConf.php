<?php

namespace Dleno\CommonCore\Conf;

/**
 * 当前请求配置
 */
class RequestConf
{
    //输出不自动转换
    const OUTPUT_NOT_FORMAT = '__NOT_FORMAT__';

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

}
