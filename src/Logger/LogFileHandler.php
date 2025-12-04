<?php

namespace Dleno\CommonCore\Logger;

use Dleno\CommonCore\Tools\Server;
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
        $thisLevel = $this->level->value;
        $recordLevel = $record->level->value;
        if ($recordLevel == Level::Debug->value) {
            return Level::Debug->value == $thisLevel;
        } elseif (in_array(
            $recordLevel,
            [Level::Error->value, Level::Critical->value, Level::Alert->value, Level::Emergency->value]
        )) {
            return Level::Error->value <= $thisLevel && Level::Emergency->value >= $thisLevel;
        } else {
            return Level::Info->value <= $thisLevel && Level::Warning->value >= $thisLevel;
        }
    }

    protected function write(LogRecord $record): void
    {
        $record->formatted .= '||Trace-Id::' . Server::getTraceId();
        $record->formatted = str_replace(["\r", "\n"], '', $record->formatted . '');
        parent::write($record);
    }
}
