<?php
namespace Dleno\CommonCore\Traits;

use ReflectionClass;

/**
 * 对象属性操作
 */
trait ObjectAttribute
{
    /**
     * 將對象屬性轉為数组
     * @param bool $toUnderline 是否需要将所有key转换成下划线方式(方便操作数据库)
     * @return array
     */
    public function toArray(bool $toUnderline = true): array
    {
        $data         = [];
        $reflectClass = (new ReflectionClass($this));
        $properties   = $reflectClass->getProperties();
        foreach ($properties as $property) {
            //获得获取属性的get方法
            $method = 'get' . $this->underlineToCapital($property->getName());
            //是否需要转小驼峰
            $objProperty = $toUnderline
                ? $this->capitalToUnderline($property->getName())
                : $property->getName();
            if ($reflectClass->hasMethod($method)) {
                $data[$objProperty] = $this->$method() ?? null;
            }
        }
        return $data;
    }

    /**
     * 将数组数据填充到对象属性
     * @param array $data
     * @return static
     */
    public function fill($data): self
    {
        if (empty($data)) {
            return $this;
        }
        $reflectionParams = new ReflectionClass($this);
        $properties       = $reflectionParams->getProperties();
        foreach ($properties as $property) {
            //分别获取属性的下划线/大驼峰名
            $underLineName = $this->capitalToUnderline($property->getName());
            $capitalName   = $this->underlineToCapital($property->getName());
            $setMethod     = 'set' . $capitalName;
            $value         = $data[$underLineName] ?? ($data[lcfirst($capitalName)] ?? false);
            if ($value !== false && $value !== '' && $reflectionParams->hasMethod($setMethod)) {
                $this->$setMethod($value);
            }
        }
        return $this;
    }

    /**
     * 下划线转大驼峰
     * 如:target_account_id   ->  TargetAccountId
     * @param $str
     * @return string
     */
    private function underlineToCapital($str)
    {
        $str = str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
        return $str;
    }

    /**
     * 大小驼峰转下划线
     * 如:targetAccountId   ->  target_account_id
     * @param $str
     * @return string
     */
    private function capitalToUnderline($str)
    {
        $temp = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $asciiCode = ord($str[$i]);
            if ($asciiCode >= 65 && $asciiCode <= 90) {
                $temp .= ($i == 0?'':'_') . chr($asciiCode + 32);
            } else {
                $temp .= $str[$i];
            }
        }
        return $temp;
    }

}
