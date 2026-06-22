<?php
namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标记接口不写入出参日志。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class OutputNoLog extends AbstractAnnotation
{
}
