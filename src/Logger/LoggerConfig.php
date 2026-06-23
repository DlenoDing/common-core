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
     * 日志分组(group)统一定义——单一真相源。
     * group 决定写入「哪一套文件」(logger.php 的顶层键即 group);Logger 等处一律引用这些常量,勿散落字面量。
     * 业务方要新增自定义 group:在 config/autoload/logger.php 的自定义段加一组(可用 fileHandler() 生成 handler),
     * 再用 Logger::groupLog($channel, '自定义组名') 统一写入。
     */
    const GROUP_DEFAULT  = 'default';
    const GROUP_SQL      = 'sql';
    const GROUP_API      = 'api';
    const GROUP_BUSINESS = 'business';

    /**
     * 分组 => 日志文件名前缀。
     * 注意:default 分组的文件名前缀是 'system'(system-debug/info/error.log)。
     */
    private const GROUPS = [
        self::GROUP_DEFAULT  => 'system',
        self::GROUP_SQL      => 'sql',
        self::GROUP_API      => 'api',
        self::GROUP_BUSINESS => 'business',
    ];

    /** 每个分组固定三个级别 handler:文件名级别段 => Monolog Level */
    private const LEVELS = [
        'debug' => Level::Debug,
        'info'  => Level::Info,
        'error' => Level::Error,
    ];

    /**
     * 获取默认配置(由分组/级别映射工厂生成,等价于原先逐个分组写死的 12 个 handler 块)
     * @return array
     */
    public static function getDefaultConfig(): array
    {
        $maxFiles = (int) env('LOG_MAX_FILES', self::DEFAULT_MAX_FILES);

        $config = [];
        foreach (self::GROUPS as $group => $prefix) {
            $handlers = [];
            foreach (self::LEVELS as $levelName => $level) {
                $handlers[] = self::fileHandler("{$prefix}-{$levelName}.log", $level, $maxFiles);
            }
            $config[$group] = ['handlers' => $handlers];
        }
        return $config;
    }

    /**
     * 单个文件日志 handler 配置(LogFileHandler + 统一 LineFormatter)。公开供业务方扩展自定义分组/级别时复用:
     *   在 config/autoload/logger.php 的自定义段直接 LoggerConfig::fileHandler('xxx-debug.log', Level::Debug) 生成,
     *   即可与包内日志保持同一格式/目录/轮转策略。
     *
     * @param string   $file     日志文件名(置于 runtime/logs/api/ 下)
     * @param Level    $level    日志级别
     * @param int|null $maxFiles 轮转保留文件数;省略则取 env('LOG_MAX_FILES') 默认值(与默认配置同源)
     */
    public static function fileHandler(string $file, Level $level, ?int $maxFiles = null): array
    {
        $maxFiles ??= (int) env('LOG_MAX_FILES', self::DEFAULT_MAX_FILES);

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
