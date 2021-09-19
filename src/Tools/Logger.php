<?php

namespace Dleno\CommonCore\Tools;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class Logger
{
    //系统日志渠道定义
    const SYSTEM_CHANNEL_EXCEPTION = 'Exception';

    //接口日志渠道定义
    const API_CHANNEL_RESPONSE = 'RESPONSE';

    //SQL日志渠道定义
    const SQL_CHANNEL_QUERY = 'QUERY';

    /**
     * 获取控制台日志logger
     * @param string $channel
     * @return LoggerInterface
     */
    public static function stdoutLog(): LoggerInterface
    {
        $logger = get_inject_obj(StdoutLoggerInterface::class);
        return $logger;
    }

    /**
     * 获取系统日志logger
     * @param string $channel
     * @return LoggerInterface
     */
    public static function systemLog($channel = 'DEFAULT'): LoggerInterface
    {
        $group  = 'default';
        $logger = get_inject_obj(LoggerFactory::class)->get($channel, $group);
        return $logger;
    }

    /**
     * 获取API接口日志logger
     * @param string $channel
     * @return LoggerInterface
     */
    public static function apiLog($channel = 'DEFAULT'): LoggerInterface
    {
        $group  = 'api';
        $logger = get_inject_obj(LoggerFactory::class)->get($channel, $group);
        return $logger;
    }

    /**
     * 获取sql日志logger
     * @param string $channel
     * @return LoggerInterface
     */
    public static function sqlLog($channel = 'DEFAULT'): LoggerInterface
    {
        $group  = 'sql';
        $logger = get_inject_obj(LoggerFactory::class)->get($channel, $group);
        return $logger;
    }

    /**
     * 获取业务日志logger
     * @param string $channel
     * @return LoggerInterface
     */
    public static function businessLog($channel = 'DEFAULT'): LoggerInterface
    {
        $group  = 'business';
        $logger = get_inject_obj(LoggerFactory::class)->get($channel, $group);
        return $logger;
    }
}
