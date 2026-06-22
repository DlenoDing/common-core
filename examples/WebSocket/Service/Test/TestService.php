<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Service\Test;

use Dleno\CommonCore\Examples\WebSocket\Component\WsAccountComponent;
use Dleno\CommonCore\Examples\WebSocket\Conf\WsServerConf;
use Dleno\CommonCore\Examples\WebSocket\Service\BaseService;
use Dleno\CommonCore\Tools\Strings\Strings;
use Dleno\CommonCore\Websocket\Component\WsPushMsgComponent;

class TestService extends BaseService
{
    public function index(array $post): array
    {
        $push = get_inject_obj(WsPushMsgComponent::class);
        $push->pushPubMessage(WsServerConf::CMD_TYPE_NOTICE, [
            'tt'  => time(),
            'str' => Strings::makeRandStr(16),
        ]);

        $accountId = get_inject_obj(WsAccountComponent::class)->getCurrAccountId();
        $push->pushToDimMessage(
            'account_id',
            $accountId,
            WsServerConf::CMD_TYPE_NOTICE,
            [
                'tt'  => microtime(true),
                'str' => Strings::makeRandStr(32),
            ],
            5
        );

        $online = $push->checkHeartbeatOnlineByDim('account_id', [$accountId]);

        return [
            'post'   => $post,
            'online' => $online,
        ];
    }
}
