<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Server\Server;

/**
 * 启动前置校验：启用 WebSocket 服务时，运行模式必须为 SWOOLE_BASE，否则打印提示并终止整个启动。
 *
 * 原因（根因在框架层）：
 *  Hyperf WebSocket 的 per-fd 状态 —— FdCollector::$fds(决定 onMessage 是否丢弃) 与
 *  Hyperf\WebSocketServer\Context::$container(存握手 Request 等按 fd 数据) —— 均为 **worker 进程私有 static**。
 *  握手(onHandShake/onOpen)在接受连接的 worker 写入，消息(onMessage)在收到帧的 worker 读取，
 *  故要求同一连接的握手/消息/关闭落在同一 worker。
 *  - SWOOLE_BASE：每个 worker 自身为 reactor、端到端独占自己 accept 的连接 → 始终同 worker，安全。
 *  - SWOOLE_PROCESS：master 按 dispatch 分发，握手与消息可能落到不同 worker → fd 数据取不到、
 *    onMessage 打印 "fd does not exist" 直接丢消息（即注释所说"客户端FD数据会有问题"）。
 *
 * 选用 BeforeMainServerStart 事件：仅在“真正启动服务”时触发、且仅一次、在主进程内、监听之前，
 * 因此 fail-fast 不会误伤 migrate 等其它命令；此时 exit 可干净中止启动。
 */
#[Listener]
class WsServerModeCheckListener implements ListenerInterface
{
    public function __construct(private ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        // 是否启用了 WebSocket 服务（server.servers 已按 ENABLE_WS 解析；只看实际生效配置）
        $hasWs = false;
        foreach ((array) $this->config->get('server.servers', []) as $server) {
            if (($server['type'] ?? null) === Server::SERVER_WEBSOCKET) {
                $hasWs = true;
                break;
            }
        }
        if (! $hasWs) {
            return;
        }

        if ($this->config->get('server.mode') === SWOOLE_BASE) {
            return;
        }

        $lines = [
            '',
            '========================================================================',
            '[FATAL] 启动已中止：检测到启用了 WebSocket 服务，但 server.mode 不是 SWOOLE_BASE。',
            '',
            'Hyperf WebSocket 的 per-fd 状态（FdCollector / WsContext）为 worker 进程私有，',
            '必须 SWOOLE_BASE 才能保证同一连接的握手/消息落在同一 worker；',
            'SWOOLE_PROCESS 模式下握手与消息可能分到不同 worker，导致客户端 FD 数据丢失、消息被丢弃。',
            '',
            '请将 config/autoload/server.php 的 mode 改为 SWOOLE_BASE 后重新启动。',
            '========================================================================',
            '',
        ];
        fwrite(STDERR, implode(PHP_EOL, $lines) . PHP_EOL);
        // 主进程、监听前，直接终止整个服务启动
        exit(1);
    }
}
