<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Hook;

use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Examples\WebSocket\Component\WsAccountComponent;
use Dleno\CommonCore\Examples\WebSocket\Conf\WsRequestConf;
use Dleno\CommonCore\Tools\Server;
use Dleno\CommonCore\Websocket\Hook\AbstractWsHook;
use Dleno\CommonCore\Websocket\Support\WsHandshakeResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * WS 生命周期 Hook 示例。
 *
 * 复制到业务项目后,在 dependencies.php 绑定到 WsHookInterface。
 */
class AppWsHook extends AbstractWsHook
{
    public function onHandshake(ServerRequestInterface $request): WsHandshakeResult
    {
        $debug = get_query_val(WsRequestConf::REQUEST_HEADER_DEBUG, false);
        $debug = ($debug && !Server::isProd()) ? true : false;

        $clientToken = get_query_val(WsRequestConf::REQUEST_HEADER_TOKEN, '');
        if ($clientToken === '') {
            throw new HttpException('Empty Token', RcodeConf::ERROR_TOKEN);
        }

        $account = get_inject_obj(WsAccountComponent::class)->checkAccountByToken((string) $clientToken);
        $accountId = $account['account_id'] ?? 0;
        if (empty($accountId)) {
            throw new HttpException('Error Token', RcodeConf::ERROR_TOKEN);
        }

        $deviceType = (string) ($account['device_type'] ?? get_query_val(WsRequestConf::REQUEST_HEADER_DEVICE, 'unknown'));
        $request    = $request->withHeader(WsRequestConf::REQUEST_HEADER_DEBUG, $debug ? 1 : 0)
                              ->withHeader(WsRequestConf::REQUEST_HEADER_TOKEN, $clientToken)
                              ->withHeader(WsRequestConf::REQUEST_HEADER_ACCOUNT_ID, $accountId)
                              ->withHeader(WsRequestConf::REQUEST_HEADER_DEVICE, $deviceType);

        return new WsHandshakeResult($request, array_merge($account, [
            'token'       => $clientToken,
            'device_type' => $deviceType,
        ]));
    }
}
