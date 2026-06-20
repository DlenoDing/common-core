<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Contract;

/**
 * WS 连接绑定策略（契约）—— 决定「一个连接绑定哪些维度、哪些维度可被定向寻址」。
 *
 * 职责划分：
 *  - **common-core（包）持有机制**：连接↔身份的正反向索引存取（写主绑定 / 建反向索引 / 寻址 / 反删续期），
 *    由 {@see \Dleno\CommonCore\Websocket\Component\WsTokenComponent} 实现，不可被业务改坏。
 *  - **业务持有维度**：绑哪些维度、哪些可寻址，因项目而异（单端只按 account_id / 多端再分 device 等），由本接口给出。
 *
 * **无包内默认实现**：业务**必须**在 `config/autoload/dependencies.php` 把本接口绑到某个实现
 * （脚手架自带 `App\WebSocket\Bind\DefaultWsBindStrategy` = 只绑 account_id；要多端寻址就绑自己的）。
 *
 * 调用时机（均在 WsTokenComponent 内）：
 *  - setBind：用 bindDimensions() 取维度写正向主绑定，并对 addressableDimensions() 的每个维度建反向索引；
 *  - pushToDimMessage / checkClientOnline：按 (维度名,值) 取反向索引寻址；
 *  - unBind / refreshBind：依正向主绑定里的维度，反删 / 续期各反向索引。
 */
interface WsBindStrategyInterface
{
    /**
     * 返回本连接要「绑定 + 据以建反向索引」的维度集合。
     *
     * @param int   $fd       本次连接的 Swoole 文件描述符（连接的本机唯一标识）。一般用不到，
     *                        预留给"维度值需依赖具体连接"的特殊策略。
     * @param array $identity 当前连接的**完整身份**（{@see \Dleno\CommonCore\Websocket\Support\WsIdentity}），握手时由钩子解析、setBind 原样传入。
     *                        含 `account_id`、`token`，以及业务钩子返回的任意字段（如 device/client_type/account_type…）。
     *                        即：自定义策略要按哪个维度绑定，只要让握手钩子（AppWsHook::onHandshake）把对应字段放进身份即可。
     *
     * @return array dimName => dimValue 维度集合。
     *               - dimName：维度名，会成为反向索引 key 的一段（`<prefix>bind:<dimName>:<dimValue>`）；
     *               - dimValue：维度值，即按该维度寻址时传入的值。
     *               返回的**全部**维度都会写进正向主绑定（供 unBind 反删用）；其中**仅** addressableDimensions()
     *               列出的维度会另建反向索引（可被定向寻址）。
     *               例：单端 `['account_id'=>123]`；多端 `['account_id'=>123, 'device'=>'ios']`。
     */
    public function bindDimensions(int $fd, array $identity): array;

    /**
     * 声明哪些维度需要建反向索引，即哪些维度可被「定向推送 / 在线检查」按值寻址。
     *
     * 必须是 bindDimensions() 返回维度名的**子集**；未列出的维度只进正向主绑定、不可被寻址。
     *
     * @return string[] 可寻址维度名列表。
     *                  例：单端 `['account_id']`（只支持按 account_id 反查该用户全部连接）；
     *                  多端 `['account_id','device']`（既能推给某用户全部端，也能精准推给某一端）。
     */
    public function addressableDimensions(): array;
}
