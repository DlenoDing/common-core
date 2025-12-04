<?php

namespace Dleno\CommonCore\Tools\Output;

use Dleno\DingTalk\Robot;
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

    private static $traceConf = null;

    /**
     * 输出接口日志
     * @param \Throwable $throwable
     */
    public static function writeLog($throwable, $level = self::LOG_INFO, $notice = true)
    {
        //协程内执行
        Coroutine::create(
            function () use ($throwable, $level, $notice) {
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
                              'Server::%s||Message::%s||Trace::%s',
                              $server,
                              $message,
                              array_to_json($trace)
                          )
                      );

                //正确时输出调用的类和方法
                $mca = Server::getRouteMca();
                if ($mca) {
                    $method = join('\\', $mca['module']) . '\\' . $mca['ctrl'] . '->' . $mca['action'];
                } else {
                    $method = "";
                }

                //发送钉钉消息
                if ($notice) {
                    if (empty(self::$traceConf)) {
                        self::$traceConf = config('dingtalk.trace', 'default');
                        $config          = config('dingtalk.configs.' . self::$traceConf);
                        if (empty($config)) {
                            self::$traceConf = 'default';
                        }
                    }
                    Robot::get(self::$traceConf)
                         ->exception($throwable);
                }
            }
        );
    }
}