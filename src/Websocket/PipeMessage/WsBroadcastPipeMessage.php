<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\PipeMessage;

/**
 * WS 广播管道消息：投递给各事件 worker，由其推送给本地全部在线连接。
 * 字段保持标量，便于 sendMessage 序列化跨进程传输。
 */
class WsBroadcastPipeMessage
{
    /** @var string 已序列化的待推送帧文本 */
    public string $payload;

    /** @var int 需排除的 fd（如消息发送者自身），0 表示不排除 */
    public int $nfd;

    /** @var int WebSocket opcode（默认文本帧 1） */
    public int $opcode;

    /** @var int 发送标志（默认 FIN=1；启用压缩时叠加 COMPRESS） */
    public int $flags;

    public function __construct(string $payload, int $nfd = 0, int $opcode = 1, int $flags = 1)
    {
        $this->payload = $payload;
        $this->nfd     = $nfd;
        $this->opcode  = $opcode;
        $this->flags   = $flags;
    }
}
