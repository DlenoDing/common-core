<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools;

use Hyperf\Contract\TranslatorInterface;

use function Hyperf\Config\config;

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
        $parsedKey = self::parseKey($key);
        $translator = get_inject_obj(TranslatorInterface::class);
        if (!$translator->has($parsedKey)) {
            return $key; // 语言包无此key，原样返回，不污染原始内容
        }
        return is_null($number)
            ? $translator->trans($parsedKey, $replace)
            : $translator->transChoice($parsedKey, $number, $replace);
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
     * 设置当前语言。
     * 安全:locale 会被翻译器用于拼语言文件路径,而本方法的入参常来自客户端(Client-Language / Accept-Language)。
     * 故只允许安全字符 [A-Za-z0-9_-](涵盖 en / zh_CN / pt_BR 等),含 ../ 、/ 、. 等的值直接拒绝、回退默认 locale,
     * 杜绝路径穿越/locale 注入。
     * @param $language
     */
    public static function setLang($language)
    {
        $language = is_string($language) ? $language : '';
        if ($language === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $language)) {
            $language = (string) config('translation.locale', config('translation.fallback_locale', 'en'));
        }
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
