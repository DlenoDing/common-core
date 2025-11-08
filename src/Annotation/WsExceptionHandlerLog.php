<?php

namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

#[Attribute(Attribute::TARGET_METHOD)]
class WsExceptionHandlerLog extends AbstractAnnotation
{
    // some code
}
