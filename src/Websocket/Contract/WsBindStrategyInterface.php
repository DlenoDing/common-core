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
 *  - pushToDimMessage / checkRealtimeOnlineByDim：按 (维度名,值) 取反向索引寻址；
 *    checkHeartbeatOnlineByDim：读 presence 索引(由 onlineCheckDimensions() 决定哪些维度有 presence)；
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

    /**
     * 声明哪些维度「同一维度值下只允许一个连接」（后登录踢前登录 / 单点登录）。
     * 返回空数组（默认）→ 同维度值可挂多个连接（多端 / 多 tab）。
     *  - 必须是 addressableDimensions() 的**子集**（强制唯一需用反向索引反查旧连接）。
     *  - 维度值可以是组合字段：在 bindDimensions() 里把多个字段拼成一个维度值即可，
     *    例如 `['login' => $identity['account_id'].':'.$identity['device']]` 再把 'login' 列入本方法，
     *    即实现「同一 account_id+device 只允许一个连接」。
     *  - 由 common-core 在 setBind 时强制：写入本连接后，踢掉同值下的其它连接（跨机经队列、旧连接 onClose 自清绑定）。
     *  - 性能：仅对所列维度多做一次极小的反向 hash 读取；返回空则零开销。
     *
     * 默认实现见 {@see \Dleno\CommonCore\Websocket\Strategy\AbstractWsBindStrategy}（返回 []）；
     * 业务策略继承该抽象基类即默认返回空，按需 override 本方法。
     *
     * @return string[] 需强制单连接的维度名列表
     */
    public function uniqueDimensions(): array;

    /**
     * 声明哪些维度可被「心跳级在线检查」(checkHeartbeatOnlineByDim / checkHeartbeatOnlineAllByDim) 按值查询。
     * 框架为这些维度(以及 uniqueDimensions(),见下)在 setBind 时维护 presence 索引;不在此列的维度不能做在线检查。
     *
     *  - **必须是 bindDimensions() 返回维度名的子集**(否则该维度未被绑定、presence 无从建);**不要求**是 addressableDimensions()
     *    (心跳 presence 独立于寻址用的反向索引)。
     *    ⚠️ **配错陷阱**:若把某 dim 放进本方法、但 bindDimensions() 不返回它,则 setBind/refreshBind/unBind 都会静默跳过该 dim、
     *    presence 永远建不起来,而 checkHeartbeatOnlineByDim('该dim',…) 又因维度合法而执行 → **静默返回全 false(表现成"用户都不在线")**。
     *    框架无法静态校验(bindDimensions 按身份动态返回),故务必保证本方法所列维度确实会被 bindDimensions() 绑定。
     *  - **业务承诺:所列维度的单 value 连接数可控**(如 account_id 多端也就几条)。**切勿放低基数分组维度**
     *    (device_type/channel/language 这类一个 value 挂海量连接),那是寻址推送(addressableDimensions)该干的,拿来做在线检查会拖垮 Redis。
     *  - **框架会自动并入 uniqueDimensions()**:unique 维度每值单连接、天然适合检查,即使没列进本方法也允许检查(防漏设)。
     *  - 默认 [](见 AbstractWsBindStrategy):此时仅 uniqueDimensions 维度可被在线检查。
     *
     * @return string[] 可在线检查的维度名列表
     */
    public function onlineCheckDimensions(): array;
}
