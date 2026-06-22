<?php

namespace Dleno\CommonCore\Websocket\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标记 WebSocket 异常处理方法需要走 WS 响应日志切面。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class WsExceptionHandlerLog extends AbstractAnnotation
{
}
