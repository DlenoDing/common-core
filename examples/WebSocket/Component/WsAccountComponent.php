<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Component;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Examples\WebSocket\Conf\WsRequestConf;
use Hyperf\Context\Context;

class WsAccountComponent extends BaseCoreComponent
{
    public function checkAccountByToken(string $clientToken): array
    {
        // 示例:替换为业务登录态查询。无效 token 应抛异常或返回空。
        if ($clientToken === '') {
            throw new HttpException('Empty Token');
        }

        return [
            'account_id'  => 1,
            'token'       => $clientToken,
            'device_type' => get_header_val(WsRequestConf::REQUEST_HEADER_DEVICE, 'unknown'),
        ];
    }

    public function getCurrAccountId(): int|string
    {
        $accountId = Context::get(WsRequestConf::REQUEST_ACCOUNT_ID);
        if ($accountId === null) {
            $accountId = get_header_val(WsRequestConf::REQUEST_HEADER_ACCOUNT_ID, 0);
            Context::set(WsRequestConf::REQUEST_ACCOUNT_ID, $accountId);
        }
        return $accountId;
    }
}
