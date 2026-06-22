<?php

namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标记接口返回数据不做默认 key 驼峰化和值格式化。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class OutputNotFormat extends AbstractAnnotation
{
}
