<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\PipeMessage;

/**
 * fd 检查消息（批量 + 全员应答协议）
 * Class FdCheckPipeMessage
 * @package Dleno\CommonCore\Websocket\PipeMessage
 */
class FdCheckPipeMessage
{
    const TYPE_CHECK_TO     = 1;//请求
    const TYPE_CHECK_RETURN = 2;//回包

    const MODE_LIST = 'list';//查指定 fd 集
    const MODE_ALL  = 'all'; //查本服务器全量

    /**
     * @var int 消息类型
     */
    public $type;

    /**
     * @var string 请求模式：MODE_LIST | MODE_ALL（仅请求有意义）
     */
    public $mode;

    /**
     * @var int[] 请求：候选 fd 数组；回包：命中的活跃 fd 子集
     */
    public $fds;

    /**
     * @var int|null 批次请求号：用于在来源进程内将回包路由回对应等待协程
     */
    public $rid;

    /**
     * @var int 发送方进程 pid（构造时自动填充）
     */
    public $pid;

    /**
     * @var int 来源进程 pid：回包按 spid==getmypid() 过滤路由
     */
    public $spid;

    /**
     * @var int 来源进程的 worker_id：
     *   >=0 来源为事件/Task Worker（回包走 $server->sendMessage 精准寻址）；
     *   -1  来源为自定义(用户)进程（回包走 ProcessCollector 广播）。
     */
    public $sworkerId;

    /**
     * @var int 回包：应答 Worker 的 id（用于逐 Worker 完成度跟踪）
     */
    public $fromWid;

    /**
     * @var bool 回包：是否该 Worker 的最后一个分块
     */
    public $last;

    /**
     * @param int   $type   TYPE_CHECK_TO | TYPE_CHECK_RETURN
     * @param array $params mode/fds/rid/spid/sworkerId/fromWid/last
     */
    public function __construct(int $type, array $params = [])
    {
        $this->type      = $type;
        $this->mode      = $params['mode']      ?? self::MODE_LIST;
        $this->fds       = $params['fds']       ?? [];
        $this->rid       = $params['rid']       ?? null;
        $this->pid       = getmypid();
        $this->spid      = $params['spid']      ?? $this->pid;
        $this->sworkerId = $params['sworkerId'] ?? -1;
        $this->fromWid   = $params['fromWid']   ?? -1;
        $this->last      = $params['last']      ?? true;
    }
}
