<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Listener;

use Dleno\CommonCore\Websocket\Contract\WsBindStrategyInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Server\Server;
use Psr\Container\ContainerInterface;

/**
 * 启动前置校验：启用 WebSocket 服务时，绑定策略的 uniqueDimensions() 必须是 addressableDimensions() 的子集，
 * 否则打印提示并终止整个启动（fail-fast）。
 *
 * 原因：uniqueDimensions（单连接/踢旧）要靠反向索引反查旧连接，而反向索引只对 addressableDimensions 才建。
 * 若把某维度列入 uniqueDimensions 却忘了列入 addressableDimensions，单连接会在运行时**静默失效**且无任何报错；
 * 这是配置错误，越早（启动期）暴露越好，故直接中止启动。
 *
 * 选用 BeforeMainServerStart：仅在真正启动服务时触发、且仅一次、在主进程内、监听之前，
 * 不会误伤 migrate 等其它命令，此时 exit 可干净中止启动。
 */
#[Listener]
class WsBindStrategyCheckListener implements ListenerInterface
{
    public function __construct(private ConfigInterface $config, private ContainerInterface $container)
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
        // 仅启用了 WebSocket 服务时才校验
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

        // 未绑定/无法解析绑定策略 → 不在此处理（交由实际使用处暴露），避免引入新的失败面
        try {
            $strategy = $this->container->get(WsBindStrategyInterface::class);
        } catch (\Throwable $e) {
            return;
        }

        $unique = (array) $strategy->uniqueDimensions();
        if (empty($unique)) {
            return;
        }
        $addressable = (array) $strategy->addressableDimensions();
        $invalid     = array_values(array_diff($unique, $addressable));
        if (empty($invalid)) {
            return;
        }

        $lines = [
            '',
            '========================================================================',
            '[FATAL] 启动已中止：WsBindStrategy::uniqueDimensions() 含未在 addressableDimensions() 内的维度。',
            '',
            '  违规维度：' . implode(', ', $invalid),
            '  uniqueDimensions  = [' . implode(', ', $unique) . ']',
            '  addressableDimensions = [' . implode(', ', $addressable) . ']',
            '',
            'uniqueDimensions（单连接/踢旧）依赖反向索引反查旧连接，反向索引只对 addressableDimensions 建。',
            '请把上述违规维度同时加入 addressableDimensions()，或从 uniqueDimensions() 移除，再重新启动。',
            '========================================================================',
            '',
        ];
        fwrite(STDERR, implode(PHP_EOL, $lines) . PHP_EOL);
        // 主进程、监听前，直接终止整个服务启动
        exit(1);
    }
}
