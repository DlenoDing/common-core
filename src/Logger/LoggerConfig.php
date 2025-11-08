<?php

namespace Dleno\CommonCore\Logger;

use Monolog\Formatter;
use Monolog\Level;

/**
 * 日志配置相关
 * Class LoggerConfig
 * @package Dleno\CommonCore\Logger
 */
class LoggerConfig
{
    /**
     * 获取默认配置
     * @return array
     */
    public static function getDefaultConfig(): array
    {
        return [
            'default' => [
                'handlers' => [
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/system-debug.log',
                            'level' => Level::Debug,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/system-info.log',
                            'level' => Level::Info,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/system-error.log',
                            'level' => Level::Error,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'sql' => [
                'handlers' => [
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/sql-debug.log',
                            'level' => Level::Debug,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/sql-info.log',
                            'level' => Level::Info,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/sql-error.log',
                            'level' => Level::Error,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'api' => [
                'handlers' => [
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/api-debug.log',
                            'level' => Level::Debug,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/api-info.log',
                            'level' => Level::Info,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/api-error.log',
                            'level' => Level::Error,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'business' => [
                'handlers' => [
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/business-debug.log',
                            'level' => Level::Debug,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/business-info.log',
                            'level' => Level::Info,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                    [
                        'class' => LogFileHandler::class,
                        'constructor' => [
                            'filename' => BASE_PATH . '/runtime/logs/api/business-error.log',
                            'level' => Level::Error,
                            'maxFiles' => 1,
                        ],
                        'formatter' => [
                            'class' => Formatter\LineFormatter::class,
                            'constructor' => [
                                'format' => "%datetime%||%channel%||%level_name%||%message%||%context%||%extra%\n",
                                'dateFormat' => null,
                                'allowInlineLineBreaks' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
