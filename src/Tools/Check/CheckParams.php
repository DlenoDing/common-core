<?php


namespace Dleno\CommonCore\Tools\Check;


use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Dleno\CommonCore\Exception\AppException;
use Dleno\CommonCore\Tools\Language;
use Dleno\CommonCore\Tools\Server;

class CheckParams
{
    /**
     * 执行接口参数校验
     *
     * @param array $rules 规则详见：https://hyperf.wiki/2.0/#/zh-cn/validation
     * @param array $params
     * @param array $customAttributes
     * @param array $messages
     * @return bool
     */
    public static function check(array $rules, array $params, array $customAttributes = [], array $messages = [])
    {
        $mca    = Server::getRouteMca();
        //处理自定义字段属性
        foreach ($rules as $k => $v) {
            if (!isset($customAttributes[$k])) {
                $customAttributes[$k] = self::getValidationLang('Validation:' . join(':', $mca['module']) . '.' . $k, $k);
            }
        }
        //处理自定义提示消息
        foreach ($messages as $k => $v) {
            $messages[$k] = self::getValidationLang('Validation:' . $v, $v);
        }

        //构建验证器
        $validationFactory = get_inject_obj(ValidatorFactoryInterface::class);
        $validator         = $validationFactory->make($params, $rules, $messages, $customAttributes);
        $validator->validate();

        return true;
    }

    /**
     * 获取语言文案内容
     * @param $key
     * @param null $default
     * @return mixed|null
     */
    private static function getValidationLang($key, $default = null)
    {
        if (Language::has($key)) {
            return Language::get($key);
        }
        return $default;
    }
}