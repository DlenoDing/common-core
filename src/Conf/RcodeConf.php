<?php

namespace Dleno\CommonCore\Conf;

/**
 * 接口返回码公共配置项
 * 接口成功返回CODE_SUCCESS；一般的非需要特殊定义的失败返回ERRNO_NORMAL
 */
class RcodeConf
{
    //----统一返回格式----
    /* {
        "code": 0,
        "trace": ["ok"],//系统跟踪消息-内部使用
        "msg": "显示提示", //外显提示文案，非空string时才显示
        "data": {
            "list": [//列表数据集
                {***},
                {***},
            ],
            "pager": {//分页数据
                "pageSize": 10,//每页记录大小
                "currPage": "1",//当前页
                "itemCount": "178",//总记录数
                "pageCount": "18",//总页数
            }
        }
    } */
    //默认返回数据
    public static $dftReturn = [
        //正确时统一为0
        'code'  => self::ERRNO_NORMAL, //默认返回状态码
        'msg'   => '',//外显提示文案，非空string时才显示
        'trace' => [],//系统跟踪消息-内部使用
        'data'  => [],//返回数据体-输出时转对象；分页数据则放入下面的list、pager中
    ];

    /* 自定义错误号 */
    const
        SUCCESS = 0, //成功
        ERROR_BAD = 400,//错误请求(Bad Request)
        ERROR_TOKEN = 401,//没有登录(未登录或失效)
        ERROR_SIGN = 403,//没有权限(鉴权)
        ERROR_NOTFOUND = 404,//NotFound
        ERROR_METHOD_NOT_ALLOWED = 405,//Method not allowed
        ERROR_SERVER = 500,//服务器错误

        ERRNO_NORMAL = 1000, //普通失败
        ERRNO_PARAMS = 1001, //参数校验失败
        ERRNO_FREQUENCY_LIMIT = 1002, //频率限制
        ERRNO_CONFIRM = 1099, //需再次确认的错误

        /* 占位 */
        ERRNO_END = 999999;
}
