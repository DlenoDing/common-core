<?php

namespace Dleno\CommonCore\Traits;

use Dleno\CommonCore\Tools\AttributeReflection;

/**
 * 对象属性操作（DTO/实体 与 数组/DB 的互转）。
 *
 * ★★★ 核心机制：`'(null)'` 哨兵 —— 表达"显式置 NULL"，请勿当成魔法字符串/死代码删除或简化 ★★★
 * 属性有三态，{@see toData()} 据此实现"部分字段更新（含显式置空）"：
 *   1) 真 null（属性从未 set/fill 过，保持默认）  → toData **排除该字段** → DB 不更新该列；
 *   2) 哨兵字符串 '(null)'（业务显式 `setX('(null)')` 或 `fill(['x'=>'(null)'])`）
 *                                                  → toData/toArray **物化成真 null** → DB 把该列更新为 NULL；
 *   3) 普通值                                       → 原样带上 → DB 更新为该值。
 * 用途：把 DB 行映射成对象时只 set/fill"要更新的字段"，更新取值用 `toData()`，即可只更新对应列、且支持主动置 NULL。
 * 删/改任何 `'(null)'` 相关逻辑都会让"显式置 NULL 更新"失效，属严重事故（全量/错误更新），改前务必通读全部 toData 调用点。
 *
 * 注意（已知行为，非 bug）：
 *  - `getX()` 返回的是**内部值**：若该字段被设为哨兵 '(null)'，`getX()` 原样返回字符串 '(null)'（**不转 null**）；
 *    要"物化后的值"请用 `toData()/toArray()`。构造置空更新对象时若要回读该字段，用 `=== '(null)'` 判，别直接当 null 用。
 *  - 若真实数据本身恰为字符串 "(null)"，会被 toArray/toData 误物化成 null —— 哨兵设计的固有取舍，极罕见，按现状接受。
 */
trait ObjectAttribute
{
    /**
     * get call拦截
     *
     * @param string $method
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        if (!method_exists($this, $method)) {
            return null;
        }
        $val = $this->{$method}(...$parameters);
        //仅当通过 __call 走 get*（即 getter 为 private/protected）时，把哨兵 '(null)' 物化为真 null；
        //public getter 直调不经此处（故 public getter 回读哨兵会得到 '(null)'，见类注释"已知行为"）。
        if (substr($method, 0, 3) == 'get') {
            $val = ($val === '(null)' ? null : $val);
        }
        return $val;
    }

    /**
     * 將對象屬性轉為数组
     * @param bool $toUnderline 是否需要将所有key转换成下划线方式(方便操作数据库)
     * @return array
     */
    public function toArray(bool $toUnderline = true): array
    {
        $data         = [];
        [$reflectClass, $properties] = AttributeReflection::of($this);
        foreach ($properties as $property) {
            //获得获取属性的get方法
            $propertyName = $this->underlineToCapital($property->getName());
            $method       = 'get' . $propertyName;
            if (!$reflectClass->hasMethod($method)) {
                $method = 'is' . $propertyName;
                if (!$reflectClass->hasMethod($method)) {
                    $method = $property->getName();
                    if (!$reflectClass->hasMethod($method)) {
                        continue;
                    }
                }
            }
            //是否需要转小驼峰
            $objProperty        = $toUnderline ? $this->capitalToUnderline($property->getName()) : $property->getName();
            $val                = $this->$method();
            //toArray = 全量快照：所有字段都输出（含真 null）；哨兵 '(null)' 物化为真 null。
            $data[$objProperty] = ($val === '(null)' ? null : $val);
        }
        return $data;
    }

    /**
     * 转为"部分更新"数组（DB update/insert 取值用）。与 {@see toArray()} 的区别：
     *   - **真 null 的字段直接跳过**（视为"未 set/fill、不更新"）；
     *   - 哨兵 '(null)' 物化为真 null 后**保留**（视为"显式置 NULL"，更新该列为 NULL）；
     *   - 普通值保留。
     * 即：只返回被显式 set/fill 过的字段（含显式置空），未动过的不返回 → 配合 update 实现按需更新对应列。
     * @return array
     */
    public function toData(): array
    {
        $data         = [];
        [$reflectClass, $properties] = AttributeReflection::of($this);
        foreach ($properties as $property) {
            //获得获取属性的get方法
            $propertyName = $this->underlineToCapital($property->getName());
            $method       = 'get' . $propertyName;
            if (!$reflectClass->hasMethod($method)) {
                $method = 'is' . $propertyName;
                if (!$reflectClass->hasMethod($method)) {
                    $method = $property->getName();
                    if (!$reflectClass->hasMethod($method)) {
                        continue;
                    }
                }
            }

            $objProperty = $this->capitalToUnderline($property->getName());
            $val         = $this->$method();
            //真 null = 该字段从未 set/fill（保持默认）→ 跳过，不纳入更新（这正是"只更新动过的列"的关键）。
            //注意：要把列更新为 NULL，应显式设哨兵 '(null)'（下行会物化成真 null 并保留），而非真 null。
            if (is_null($val)) {
                continue;
            }
            //哨兵 '(null)'（显式置空）→ 物化为真 null 保留 → DB 该列更新为 NULL。
            $data[$objProperty] = ($val === '(null)' ? null : $val);
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
        [$reflectClass, $properties] = AttributeReflection::of($this);
        foreach ($properties as $property) {
            //分别获取属性的下划线/大驼峰名
            $underLineName = $this->capitalToUnderline($property->getName());
            $capitalName   = $this->underlineToCapital($property->getName());
            $setMethod     = 'set' . $capitalName;
            //仅填充"在 $data 中存在且非 null"的键：isset 对 null 值返回 false，故 $data 里值为 null 的键被跳过
            //（该字段保持"未动"语义、不进 toData）。要通过 fill 把字段标记为"显式置 NULL"，请传哨兵字符串 '(null)'。
            if (isset($data[$underLineName]) || isset($data[lcfirst($capitalName)])) {
                $value = $data[$underLineName] ?? ($data[lcfirst($capitalName)] ?? null);
                //取到的 null 兜底成哨兵 '(null)'，对齐"显式置空"语义；传入的 '(null)' 字符串原样保留。
                $value = is_null($value) ? '(null)' : $value;
                if ($reflectClass->hasMethod($setMethod)) {
                    $this->$setMethod($value);
                }
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
                $temp .= ($i == 0 ? '' : '_') . chr($asciiCode + 32);
            } else {
                $temp .= $str[$i];
            }
        }
        return $temp;
    }

}
