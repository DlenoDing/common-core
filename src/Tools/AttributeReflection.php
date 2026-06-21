<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools;

use ReflectionClass;
use ReflectionProperty;

/**
 * 按类名缓存「ReflectionClass + 属性列表」,供 DTO 属性互转复用。
 *
 * ObjectAttribute / BaseParams 的 toArray/toData/fill 每次都 `new ReflectionClass($this)` + `getProperties()`,
 * 而这两者只取决于类结构(不变),适合按类名缓存一次。反射元数据(ReflectionClass/ReflectionProperty)只读、
 * 不含可变状态,可跨调用、跨协程安全复用。
 *
 * 注意:缓存刻意放在本独立类、而非各 DTO/trait 内的 static 属性——因为 `getProperties()` 默认会把静态属性
 * 也列出,若缓存做成 DTO 内 static,会污染 toArray/toData/fill 的属性迭代集、改变行为。放外置类则属性集合完全不变。
 */
final class AttributeReflection
{
    /** @var array<string, array{0: ReflectionClass, 1: ReflectionProperty[]}> */
    private static array $cache = [];

    /**
     * @return array{0: ReflectionClass, 1: ReflectionProperty[]} [反射类, 属性列表](与 `new ReflectionClass($obj)` + `getProperties()` 等价)
     */
    public static function of(object $object): array
    {
        $class = get_class($object);
        if (!isset(self::$cache[$class])) {
            $reflectClass        = new ReflectionClass($object);
            self::$cache[$class] = [$reflectClass, $reflectClass->getProperties()];
        }
        return self::$cache[$class];
    }
}
