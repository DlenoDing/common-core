<?php

namespace Dleno\CommonCore\Tools\Output;

use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Notice\DingDing;
use Dleno\CommonCore\Tools\Server;

class ErrorOutLog
{
    const LOG_DEBUG = 'debug';
    const LOG_INFO  = 'info';
    const LOG_ERROR = 'error';
    const LOG_ALERT = 'alert';

    /**
     * 输出接口日志
     * @param \Throwable $throwable
     */
    public static function writeLog($throwable, $level = self::LOG_INFO, $notice = true)
    {
        //协程内执行
        Coroutine::create(
            function () use ($throwable, $level, $notice) {
                $traceId = Server::getTraceId();
                $server  = config('app_name') . '(' . Server::getIpAddr() . ')';

                //实例化错误时就写入文件日志，防止错误被捕获
                $message = $throwable->getMessage();
                $message = sprintf('%s[%s] in %s', $message, $throwable->getLine(), $throwable->getFile());
                $message = str_replace(BASE_PATH, '', $message);
                $message = str_replace(PHP_EOL, " ", $message);
                $message = str_replace("\r", "", $message);

                $trace = $throwable->getTraceAsString();
                $trace = str_replace(BASE_PATH, '', $trace);
                $trace = explode("\n", $trace);

                //系统错误日志
                Logger::systemLog(Logger::SYSTEM_CHANNEL_EXCEPTION)
                      ->{$level}(
                          sprintf(
                              'Server::%s||Trace-Id::%s||Message::%s||Trace::%s',
                              $server,
                              $traceId,
                              $message,
                              array_to_json($trace)
                          )
                      );

                //正确时输出调用的类和方法
                $mca    = Server::getRouteMca();
                if ($mca) {
                    $method = join('\\', $mca['module']) . '\\' . $mca['ctrl'] . '->' . $mca['action'];
                } else {
                    $method = "";
                }

                //发送钉钉消息
                if ($notice) {
                    DingDing::send(
                        [
                            '运行错误'  => null,
                            'Server'   => $server,
                            'Trace-Id' => $traceId,
                            'Method'   => $method,
                            'Message'  => $message,
                            //'Trace'  => $trace,
                        ]
                    );
                    /*DingDing::send(
                        "Server::" . $server . "\nTrace-Id::" . $traceId . "\nMethod::" . $method . "\nMessage::" . $message
                    );*/
                }
            }
        );
    }
}