<?php

namespace Dleno\CommonCore\Tools\Arrays;


class ArrayTool
{
    public static function merge($arr1, $arr2)
    {
        $rs   = [];
        $keys = array_unique(array_merge($arr2 ? array_keys($arr2) : [], $arr1 ? array_keys($arr1) : []));
        foreach ($keys as $k) {
            $arr1[$k] = isset($arr1[$k]) ? $arr1[$k] : [];
            if (isset($arr2[$k]) && is_array($arr2[$k])) {
                $rs[$k] = self::merge($arr1[$k], $arr2[$k]);
            } else {
                $rs[$k] = isset($arr2[$k]) ? $arr2[$k] : $arr1[$k];
            }
        }
        return $rs;
    }
}