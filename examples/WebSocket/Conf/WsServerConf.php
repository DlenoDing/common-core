<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Conf;

class WsServerConf
{
    // 业务自定义 cmd 消息类型;底层 Redis key / 队列名由 common-core WsKeys 管理。
    public const CMD_TYPE_NOTICE      = 1;
    public const CMD_TYPE_PRIVATE_MSG = 2;
}
