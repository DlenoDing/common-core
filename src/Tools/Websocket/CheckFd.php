<?php

namespace Dleno\CommonCore\Tools\Websocket;

use Hyperf\WebSocketServer\Sender;
use Dleno\CommonCore\PipeMessage\Websocket\FdCheckPipeMessage;
use Swoole\Server;

use function Hyperf\Config\config;

class CheckFd
{
    const MAX_SLEEP = 50;//休眠最大次数
    const STEP_SLEEP = 10000;//单次休眠时长(微秒)

    public static $fds = [];

    /**
     * 检查对应FD连接是否有效
     * @param $fd
     * @return bool
     */
    public static function check($fd)
    {
        if (config('server.mode') == SWOOLE_BASE) {
            $deviceOnline = false;
            $i = 0;

            $pipeMessage = new FdCheckPipeMessage($fd, FdCheckPipeMessage::TYPE_CHECK_TO);
            $server = get_inject_obj(Server::class);
            $workerCount = $server->setting['worker_num'] - 1;
            for ($workerId = 0; $workerId <= $workerCount; ++$workerId) {
                $server->sendMessage($pipeMessage, $workerId);
            }
            while (true) {
                $i++;
                if (isset(self::$fds[$fd])) {
                    unset(self::$fds[$fd]);
                    $deviceOnline = true;
                    break;
                }
                if ($i >= self::MAX_SLEEP) {
                    break;
                }
                usleep(self::STEP_SLEEP);
            }
        } else {
            $deviceOnline = get_inject_obj(Sender::class)->check($fd);
        }

        return $deviceOnline;
    }
}
