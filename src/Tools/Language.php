<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools;

use Hyperf\Contract\TranslatorInterface;

class Language
{
    /**
     * @param $key
     * @return string
     */
    private static function parseKey($key)
    {
        //::命名空间只支持一级，:路径空间支持多级
        if (strpos($key, '::') === false) {//不存在命名空间
            $key = str_replace(':', '/', $key);//直接替换语言包key
        } else {//存在命名空间
            //拆出命名空间后替换并还原
            $key = explode('::', $key);
            $key[1] = str_replace(':', '/', $key[1]);
            $key = join('::', $key);
        }

        return $key;
    }

    /**
     * 获取语言模板内容
     * @param string $key
     * @param array $replace
     * @param array|\Countable|int $number
     * @return mixed
     */
    public static function get(string $key, array $replace = [], $number = null)
    {
        $key = self::parseKey($key);
        $translator = get_inject_obj(TranslatorInterface::class);
        if (is_null($number)) {
            return $translator->trans($key, $replace);
        } else {
            return $translator->transChoice($key, $number, $replace);
        }
    }

    /**
     * 判断KEY在当前语言中是否存在
     * @param $key
     */
    public static function has($key)
    {
        $key = self::parseKey($key);
        return get_inject_obj(TranslatorInterface::class)->has($key);
    }

    /**
     * 设置当前语言
     * @param $language
     */
    public static function setLang($language)
    {
        get_inject_obj(TranslatorInterface::class)->setLocale($language);
    }

    /**
     * 获取当前语言
     * @return string
     */
    public static function getLang()
    {
        return get_inject_obj(TranslatorInterface::class)->getLocale();
    }
}
