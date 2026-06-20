<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Listener;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnPipeMessage;
use Hyperf\Process\ProcessCollector;
use Dleno\CommonCore\Websocket\PipeMessage\FdCheckPipeMessage;
use Dleno\CommonCore\Websocket\PipeMessage\WsBroadcastPipeMessage;
use Dleno\CommonCore\Websocket\Broadcast\CheckFd;
use Dleno\CommonCore\Websocket\Broadcast\WsBroadcast;

/**
 * 接收 fd 检查的 PipeMessage（请求/回包）。
 *
 * 通过 #[Listener] 自动注册（无需业务项目在 listeners.php 手动配置）。
 * 非 FdCheckPipeMessage 的管道消息一律空转，WS 关闭时无任何副作用。
 * 注意：业务项目若曾在 config/autoload/listeners.php 手动注册本类，应移除以免重复注册。
 */
#[Listener]
class OnPipeMessageListener implements ListenerInterface
{
    /**
     * @var StdoutLoggerInterface
     */
    private $logger;

    public function __construct(StdoutLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            OnPipeMessage::class,
            \Hyperf\Process\Event\PipeMessage::class,
        ];
    }

    public function process(object $event): void
    {
        //WS 广播：投递给本事件 worker，推送给其名下全部活跃连接
        if (property_exists($event, 'data') && $event->data instanceof WsBroadcastPipeMessage) {
            //仅事件 worker 处理（OnPipeMessage 含 $event->server）；自定义进程收到则空转
            if (property_exists($event, 'server') && $event->server !== null) {
                try {
                    WsBroadcast::pushLocal($event->server, $event->data);
                } catch (\Throwable $e) {
                    $this->logger->warning($e->getMessage());
                }
            }
            return;
        }

        if (!property_exists($event, 'data') || !($event->data instanceof FdCheckPipeMessage)) {
            return;
        }
        $msg = $event->data;

        if ($msg->type == FdCheckPipeMessage::TYPE_CHECK_TO) {
            //请求：本 Worker 应答（全员应答——命中回活跃子集，未命中回空，总是回包）
            //TYPE_CHECK_TO 只经 sendMessage 投递给 Worker → 必为 OnPipeMessage（含 server）
            try {
                $server = $event->server;
                if ($msg->mode === FdCheckPipeMessage::MODE_ALL) {
                    $actives = CheckFd::localActives($server);
                } else {
                    $actives = [];
                    foreach (($msg->fds ?? []) as $fd) {
                        if (CheckFd::isFdActive($server, $fd)) {
                            $actives[] = (int)$fd;
                        }
                    }
                }
                $this->reply($server, $msg, $actives);
            } catch (\Throwable $e) {
                $this->logger->warning($e->getMessage());
            }
        } elseif ($msg->type == FdCheckPipeMessage::TYPE_CHECK_RETURN) {
            //回包：投递回本进程对应批次的等待协程
            try {
                if ($msg->spid == getmypid()) {
                    CheckFd::deliver($msg);
                }
            } catch (\Throwable $e) {
                //错误时忽略
            }
        }
    }

    /**
     * 构造并发送回包（分块 + last 标记；按 sworkerId 选择回发通道）。
     */
    private function reply($server, FdCheckPipeMessage $req, array $actives): void
    {
        //按【字节】分块:all 模式回包枚举整机在线集,大小不受请求约束,必须独立按字节切分,
        //确保每个数据报不超过自定义进程 DGRAM recv(65535) 上限,避免静默截断丢包。
        $chunks = CheckFd::chunkByBytes($actives);
        if (empty($chunks)) {
            $chunks = [[]];//即使空也回一包，使来源端可据完成度即时收敛
        }
        $n   = count($chunks);
        $wid = $server->worker_id;
        foreach ($chunks as $i => $chunk) {
            $reply = new FdCheckPipeMessage(FdCheckPipeMessage::TYPE_CHECK_RETURN, [
                'fds'       => $chunk,
                'rid'       => $req->rid,
                'spid'      => $req->spid,
                'sworkerId' => $req->sworkerId,
                'fromWid'   => $wid,
                'last'      => ($i === $n - 1),
            ]);
            $this->route($server, $reply, $req->sworkerId);
        }
    }

    /**
     * 回发：来源为可寻址 Worker(>=0) 走 sendMessage 精准回发；
     * 来源为自定义进程(-1) 走 ProcessCollector 广播(接收端按 spid+rid 过滤)。
     */
    private function route($server, FdCheckPipeMessage $reply, $sworkerId): void
    {
        if ($sworkerId >= 0) {
            $server->sendMessage($reply, $sworkerId);
            return;
        }
        $processes = ProcessCollector::all();
        if ($processes) {
            $string = serialize($reply);
            /** @var \Swoole\Process $process */
            foreach ($processes as $process) {
                $process->exportSocket()->send($string, 10);
            }
        }
    }
}
