<?php

namespace Dleno\CommonCore\Tools;

use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Tools\Check\CheckVal;
use Dleno\CommonCore\Conf\RcodeConf;

/**
 * 接口输出组件
 */
class OutPut
{
    /**
     * 返回错误
     * @param string $msg 外显提示文案，非空string时才显示
     * @param int $code 服务错误码 默认 参数错误
     * @param array $trace 系统跟踪消息
     * @return string
     */
    public static function outJsonToError($msg, $code = null, $data = [], $trace = [])
    {
        if (is_null($code)) {
            $code = RcodeConf::ERRNO_PARAMS;
        }
        return self::outputJson($data, $code, $msg, $trace);
    }

    /**
     * 返回data结果
     * @param array $retData 返回处理数据：RcodeConf::$dftReturn 格式
     * @return string
     */
    public static function outJsonToData(array $retData)
    {
        if (empty($retData)) {
            $retData = RcodeConf::$dftReturn;
        }
        $data = $retData['data'] ?? [];
        return self::outputJson(
            $data,
            $retData['code'],
            $retData['msg'],
            $retData['trace']
        );
    }

    /**
     * json输出
     * @param array $data 返回data数据对象
     * @param int $code 返回自定义错误码
     * @param string $msg 返回 显示提示
     * @param array $trace 跟踪数据，内部使用
     * */
    public static function outputJson(array $data, $code, $msg, $trace)
    {
        if (!is_array($trace)) {
            $trace = !is_null($trace) ? [$trace] : [];
        }

        if ($code != RcodeConf::SUCCESS) {
            //返回错误时，将请求跟踪号一起返回，方便定位问题
            array_unshift($trace, Server::getTraceId());
        }

        if (is_array($msg)) {
            $msg = join("; \r\n", $msg);
        }

        //格式自定义CODE
        if ($code < RcodeConf::SUCCESS || empty($code)) {
            $code = RcodeConf::SUCCESS;
        }

        //data数据转换 - 数字转为字符串；null值转为空;bool数据转0|1
        $data = $data ? self::formatData($data) : (object)array();

        $res = array(
            'code'  => $code,
            'data'  => $data,
            'msg'   => $msg ? $msg : '',
            'trace' => $trace ? $trace : [],
        );

        $result = array_to_json($res);

        return $result;
    }

    /**
     * 格式化返回数据字段Key(驼峰)+Value(字符串类型的值、自动转换时间)
     * */
    private static function formatData($data)
    {
        $tmp = [];
        foreach ($data as $key => $val) {
            $key = $key . '';//key做字符串处理
            if (is_array($val) || is_object($val)) {
                if (!empty((array)$val)) {
                    $val = self::formatData($val);
                }
            } else {
                //返回数据做字符串处理
                $val = is_numeric($val) ? "{$val}" : $val;
                $val = is_null($val) ? "" : $val;
                $val = is_bool($val) ? ($val ? 1 : 0) : $val;
                //统一时间转换为时间戳
                if (strpos(strtolower($key), 'time') !== false) {
                    if (CheckVal::isDateTime($val)) {
                        $val = strtotime($val).'';
                    } elseif ($val == GlobalConf::DEFAULT_DATE_TIME || empty($val)) {
                        $val = '0';
                    }
                }

                $val = htmlspecialchars_decode($val, ENT_QUOTES);
            }
            //统一转换为驼峰格式的KEY命名
            $newKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));

            $tmp[$newKey] = $val;
        }
        unset($data);
        return $tmp;
    }
}
