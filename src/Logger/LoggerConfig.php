<?php

namespace Dleno\CommonCore\Logger;

use Monolog\Formatter;
use Monolog\Level;

use function Hyperf\Support\env;

/**
 * 日志配置相关
 * Class LoggerConfig
 * @package Dleno\CommonCore\Logger
 */
class LoggerConfig
{
    /**
     * 按天轮转保留的日志文件数(maxFiles)。默认 1=仅留当天(与历史行为一致);
     * 业务可经 env LOG_MAX_FILES 调大保留天数,无需改代码。
     */
    private const DEFAULT_MAX_FILES = 1;

    /**
     * 频道 => 日志文件名前缀。
     * 注意:default 频道的文件名前缀是 'system'(system-debug/info/error.log)。
     */
    private const CHANNELS = [
        'default'  => 'system',
        'sql'      => 'sql',
        'api'      => 'api',
        'business' => 'business',
    ];

    /** 每个频道固定三个级别 handler:文件名级别段 => Monolog Level */
    private const LEVELS = [
        'debug' => Level::Debug,
        'info'  => Level::Info,
        'error' => Level::Error,
    ];

    /**
     * 获取默认配置(由频道/级别映射工厂生成,等价于原先逐个频道写死的 12 个 handler 块)
     * @return array
     */
    public static function getDefaultConfig(): array
    {
        $maxFiles = (int) env('LOG_MAX_FILES', self::DEFAULT_MAX_FILES);

        $config = [];
        foreach (self::CHANNELS as $channel => $prefix) {
            $handlers = [];
            foreach (self::LEVELS as $levelName => $level) {
                $handlers[] = self::fileHandler("{$prefix}-{$levelName}.log", $level, $maxFiles);
            }
            $config[$channel] = ['handlers' => $handlers];
        }
        return $config;
    }

    /**
     * 单个文件日志 handler 配置(LogFileHandler + LineFormatter)。
     */
    private static function fileHandler(string $file, Level $level, int $maxFiles): array
    {
        return [
            'class' => LogFileHandler::class,
            'constructor' => [
                'filename' => BASE_PATH . '/runtime/logs/api/' . $file,
                'level' => $level,
                'maxFiles' => $maxFiles,
            ],
            'formatter' => [
                'class' => Formatter\LineFormatter::class,
                'constructor' => [
                    'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                    'dateFormat' => null,
                    'allowInlineLineBreaks' => true,
                ],
            ],
        ];
    }
}
