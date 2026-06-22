<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Websocket\Component\WsServerComponent;

/**
 * 控制通道(在线核验 / 主动断连)的队列路由——由通道两端共同 use:
 *   - 生产者 {@see \Dleno\CommonCore\Websocket\Job\CheckOnlineJob} / {@see \Dleno\CommonCore\Websocket\Job\CloseMessageJob}
 *   - 消费进程 {@see \Dleno\CommonCore\Websocket\Process\DcsControlConsumer}
 *
 * 路由策略随 dedicated_queue 开关:
 *   - 开 → 独立控制队列 ws:queue:ctl:<sv> + 配置段 websocket.dedicated_queue;
 *   - 关 → 回落实时消息队列 + 配置段 websocket.queue(与未引入独立队列前完全一致,零行为变化)。
 * 队列名与配置段严格同步,保证生产 Job 解析的驱动配置与其落入队列(及该队列消费进程)一致。
 */
trait ControlQueueRouting
{
    /**
     * 本通道队列名(开关开→独立控制队列;关→回落实时消息队列)。
     * @param string|null $server 目标服务器(null=本机)
     */
    public static function resolveQueue($server = null): string
    {
        $sv = get_inject_obj(WsServerComponent::class)->getServerKey($server);
        return WsProcessSwitch::dedicatedQueueEnabled()
            ? WsKeys::dedicatedQueueName($sv)
            : WsKeys::queueName($sv);
    }

    /**
     * 本通道的业务可控配置段,与 resolveQueue 路由同步。
     */
    public static function resolveConfigKey(): string
    {
        return WsProcessSwitch::dedicatedQueueEnabled()
            ? 'websocket.dedicated_queue'
            : 'websocket.queue';
    }
}
