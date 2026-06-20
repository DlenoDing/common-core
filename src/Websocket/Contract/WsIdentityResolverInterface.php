<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Contract;

/**
 * WS 身份解析（鉴权）—— 唯一对外业务接口。
 * 握手时按 token 解析身份；无效则抛异常或返回空。返回数组至少含 account_id。
 * 替代脚手架 WsAccountComponent::checkAccountByToken。
 */
interface WsIdentityResolverInterface
{
    /**
     * @param string $token 客户端握手 token
     * @return array 身份数组，至少含 ['account_id' => ...]；无效返回空数组或抛异常
     */
    public function resolveByToken(string $token): array;
}
