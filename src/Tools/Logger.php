<?php

namespace Dleno\CommonCore\Tools;

use Dleno\CommonCore\Logger\LoggerConfig;
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
     * @return LoggerInterface
     */
    public static function stdoutLog(): LoggerInterface
    {
        return get_inject_obj(StdoutLoggerInterface::class);
    }

    /**
     * 通用「自定义分组」日志:写入【业务方在 config/autoload/logger.php 自定义段新增的附加分组】。
     *
     * 内置 default/sql/api/business 四组各有专用方法(systemLog/apiLog/sqlLog/businessLog),不必、也不应经此;
     * 本方法只为业务【额外自定义的分组】提供统一入口:先在 logger.php 自定义段定义该 group
     * (可用 {@see LoggerConfig::fileHandler()} 生成 handler),再 Logger::groupLog('自定义组名'[, $channel]) 写入。
     * 注意:$group 必须是 logger.php 已定义的组,否则 Hyperf LoggerFactory 抛 InvalidConfigException(无回退)。
     *
     * @param string $group   业务自定义分组名(须在 logger.php 已定义;内置四组请用上面的专用方法)
     * @param string $channel 日志频道名(仅作行内 %channel% 标签,不决定写哪个文件)
     * @return LoggerInterface
     */
    public static function groupLog(string $group, string $channel = 'DEFAULT'): LoggerInterface
    {
        return get_inject_obj(LoggerFactory::class)->get($channel, $group);
    }

    /**
     * 获取系统日志logger(default 分组 → system-*.log)
     * @param string $channel
     * @return LoggerInterface
     */
    public static function systemLog($channel = 'DEFAULT'): LoggerInterface
    {
        return get_inject_obj(LoggerFactory::class)->get($channel, LoggerConfig::GROUP_DEFAULT);
    }

    /**
     * 获取API接口日志logger(api 分组 → api-*.log)
     * @param string $channel
     * @return LoggerInterface
     */
    public static function apiLog($channel = 'DEFAULT'): LoggerInterface
    {
        return get_inject_obj(LoggerFactory::class)->get($channel, LoggerConfig::GROUP_API);
    }

    /**
     * 获取sql日志logger(sql 分组 → sql-*.log)
     * @param string $channel
     * @return LoggerInterface
     */
    public static function sqlLog($channel = 'DEFAULT'): LoggerInterface
    {
        return get_inject_obj(LoggerFactory::class)->get($channel, LoggerConfig::GROUP_SQL);
    }

    /**
     * 获取业务日志logger(business 分组 → business-*.log)
     * @param string $channel
     * @return LoggerInterface
     */
    public static function businessLog($channel = 'DEFAULT'): LoggerInterface
    {
        return get_inject_obj(LoggerFactory::class)->get($channel, LoggerConfig::GROUP_BUSINESS);
    }
}
