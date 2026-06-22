<?php

namespace Dleno\CommonCore\Websocket\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标记 WebSocket 控制器类,供路由扫描与 WS 响应日志切面识别。
 */
#[Attribute(Attribute::TARGET_CLASS)]
class WsController extends AbstractAnnotation
{
}
