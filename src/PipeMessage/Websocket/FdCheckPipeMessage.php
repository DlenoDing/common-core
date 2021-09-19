<?php

declare(strict_types=1);

namespace Dleno\CommonCore\PipeMessage\Websocket;

/**
 * fd检查消息
 * Class FdCheckPipeMessage
 * @package Dleno\CommonCore\PipeMessage\Websocket
 */
class FdCheckPipeMessage
{
    const TYPE_CHECK_TO     = 1;
    const TYPE_CHECK_RETURN = 2;

    /**
     * @var int
     */
    public $fd;

    /**
     * @var int
     */
    public $pid;

    /**
     * @var int
     */
    public $spid;

    /**
     * @var int
     */
    public $type;

    public function __construct($fd, $type, $spid = null)
    {
        $this->fd   = $fd;
        $this->type = $type;
        $this->pid  = getmypid();
        $this->spid = $spid ?? $this->pid;
    }
}
