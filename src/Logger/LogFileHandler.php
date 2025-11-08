<?php

namespace Dleno\CommonCore\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * LogFileHandler
 * 日志处理，存储文件
 * 将info、warning、notic等类型存储一个文件，debug类型存储一个文件，error类型存储一个文件
 * @package Dleno\CommonCore\Logger
 */
class LogFileHandler extends RotatingFileHandler
{
    /**
     * 重写该方法，作用改变日志的存储文件的方式。将debug,error，单独存储，其它的按着原来规则
     * @param array $record
     * @return bool
     */
    public function isHandling(LogRecord $record): bool
    {
        $thisLevel   = $this->level->value;
        $recordLevel = $record->level->value;
        switch ($recordLevel) {
            case Level::Debug:
                return $recordLevel == $thisLevel;
                break;
            case $recordLevel == Level::Error || $recordLevel == Level::Critical || $recordLevel == Level::Alert || $recordLevel == Level::Emergency:
                return Level::Error <= $thisLevel && Level::Emergency >= $thisLevel;
                break;
            default:
                return Level::Info <= $thisLevel && Level::Warning >= $thisLevel;
        }
    }
}
