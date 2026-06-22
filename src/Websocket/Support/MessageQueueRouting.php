<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Websocket\Component\WsServerComponent;

/**
 * 实时消息通道(数据面)的队列路由——由通道两端共同 use:
 *   - 生产者 {@see \Dleno\CommonCore\Websocket\Job\PushMessageJob}
 *   - 消费进程 {@see \Dleno\CommonCore\Websocket\Process\DcsMessageConsumer}
 *
 * 把"该通道用哪个队列名 / 哪个配置段"收敛到通道自身(单一真相),
 * 不再散落在编排器(WsPushMsgComponent)里。
 */
trait MessageQueueRouting
{
    /**
     * 本通道队列名:per-IP 实时消息队列 ws:queue:message:<sv>。
     * @param string|null $server 目标服务器(null=本机)
     */
    public static function resolveQueue($server = null): string
    {
        $sv = get_inject_obj(WsServerComponent::class)->getServerKey($server);
        return WsKeys::queueName($sv);
    }

    /**
     * 本通道的业务可控配置段(processes/concurrent.limit 等)。
     */
    public static function resolveConfigKey(): string
    {
        return 'websocket.queue';
    }
}
