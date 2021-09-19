<?php

namespace Dleno\CommonCore\Tools\ClassFunc;

class ClassRoute
{
    /**
     * 检测对应类及方法是否存在
     * @param $class
     * @param $func
     * @return bool
     */
    public static function checkExists($class, $func)
    {
        if (!class_exists($class)) {
            return false;
        }
        if (!method_exists($class, $func)) {
            return false;
        }

        return true;
    }
}