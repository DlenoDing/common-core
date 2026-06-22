<?php

namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标记异常处理方法需要走统一异常/响应日志切面。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ExceptionHandlerLog extends AbstractAnnotation
{
}
