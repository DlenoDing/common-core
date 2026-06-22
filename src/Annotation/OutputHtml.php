<?php
namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标记接口返回 HTML/纯文本响应,跳过默认 JSON 错误体格式。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class OutputHtml extends AbstractAnnotation
{
}
