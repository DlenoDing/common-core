<?php

namespace Dleno\CommonCore\Websocket\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

#[Attribute(Attribute::TARGET_METHOD)]
class WsExceptionHandlerLog extends AbstractAnnotation
{
}
