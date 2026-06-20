<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Contract\Websocket;

/**
 * WS 连接绑定策略 —— 绑定哪些维度由业务定义（token / device / 多端…各项目不同）。
 * 包提供"连接↔身份"的存取与反向索引机制；维度由本接口给出。
 * 默认实现 DefaultWsBindStrategy = 现状（account_id + token，按 account_id 可寻址）。
 */
interface WsBindStrategyInterface
{
    /**
     * 给定连接 fd 与已解析身份，返回本连接要绑定/建反向索引的维度集合。
     * @param int $fd
     * @param array $identity 由 WsIdentityResolver 解析、握手写入的身份（含 account_id、token 等）
     * @return array dimName => dimValue，例：['account_id'=>123, 'token'=>'abc', 'device'=>'ios']
     */
    public function bindDimensions(int $fd, array $identity): array;

    /**
     * 哪些维度可被"定向推送 / 在线检查"寻址（反向索引）。
     * @return array dim 名列表，例：['account_id']（多端寻址再加 'device' 等）
     */
    public function addressableDimensions(): array;
}
