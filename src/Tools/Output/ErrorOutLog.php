<?php

namespace Dleno\CommonCore\Tools\Output;

use Dleno\DingTalk\Robot;
use Hyperf\Coroutine\Coroutine;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;

use function Hyperf\Config\config;

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

                //遍历 previous 异常链:重包装(如 RpcMqCall 归一成 ServerException)时原始异常挂在 previous,
                //此处把原始异常的 类型/code/message/出错点/堆栈 一并落日志,保留真实出错位置便于排障。
                $causes = [];
                $prev   = $throwable->getPrevious();
                while ($prev !== null) {
                    $causes[] = str_replace(
                        [BASE_PATH, PHP_EOL, "\r"],
                        ['', ' ', ''],
                        sprintf(
                            '%s(%s): %s[%s] in %s | %s',
                            get_class($prev),
                            $prev->getCode(),
                            $prev->getMessage(),
                            $prev->getLine(),
                            $prev->getFile(),
                            $prev->getTraceAsString()
                        )
                    );
                    $prev = $prev->getPrevious();
                }

                //系统错误日志
                $logMsg = sprintf(
                    'Server::%s||Message::%s||Trace::%s',
                    $server,
                    $message,
                    array_to_json($trace)
                );
                if (!empty($causes)) {
                    $logMsg .= '||Cause::' . array_to_json($causes);
                }
                Logger::systemLog(Logger::SYSTEM_CHANNEL_EXCEPTION)
                      ->{$level}($logMsg);

                //正确时输出调用的类和方法
                $mca = Server::getRouteMca();
                if ($mca) {
                    $method = join('\\', $mca['module']) . '\\' . $mca['ctrl'] . '->' . $mca['action'];
                } else {
                    $method = "";
                }

                //发送钉钉消息
                if ($notice) {
                    if (class_exists(Robot::class)) {
                        if (empty(self::$traceConf)) {
                            self::$traceConf = config('dingtalk.trace', 'default');
                            $config    = config('dingtalk.configs.' . self::$traceConf);
                            if (empty($config)) {
                                self::$traceConf = 'default';
                            }
                        }
                        Robot::get(self::$traceConf)
                             ->exception($throwable);
                    }
                }
            }
        );
    }
}