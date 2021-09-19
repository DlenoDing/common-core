<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Listener\Websocket;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnPipeMessage;
use Hyperf\Process\ProcessCollector;
use Dleno\CommonCore\PipeMessage\Websocket\FdCheckPipeMessage;
use Dleno\CommonCore\Tools\Websocket\CheckFd;

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

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event)
    {
        if (property_exists($event, 'data')) {
            if ($event->data instanceof FdCheckPipeMessage) {
                if ($event->data->type == FdCheckPipeMessage::TYPE_CHECK_TO) {
                    try {
                        $info = $event->server->connection_info($event->data->fd);
                        if (($info['websocket_status'] ?? null) === WEBSOCKET_STATUS_ACTIVE) {
                            $pipeMessage = new FdCheckPipeMessage($event->data->fd, FdCheckPipeMessage::TYPE_CHECK_RETURN, $event->data->spid);
                            $processes = ProcessCollector::all();
                            if ($processes) {
                                $string = serialize($pipeMessage);
                                /** @var \Swoole\Process $process */
                                foreach ($processes as $process) {
                                    $process->exportSocket()->send($string, 10);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        //错误时忽略
                        var_dump($e->getMessage());
                    }
                } elseif ($event->data->type == FdCheckPipeMessage::TYPE_CHECK_RETURN) {
                    try {
                        if($event->data->spid == getmypid()) {
                            CheckFd::$fds[$event->data->fd] = true;
                        }
                    } catch (\Exception $e) {
                        //错误时忽略
                    }
                }
            }
        }
    }
}