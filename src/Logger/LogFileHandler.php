<?php

namespace Dleno\CommonCore\Logger;

use Dleno\CommonCore\Tools\Server;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

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
    public function isHandling(array $record): bool
    {
        switch ($record['level']) {
            case Logger::DEBUG:
                return $record['level'] == $this->level;
                break;
            case $record['level'] == Logger::ERROR || $record['level'] == Logger::CRITICAL || $record['level'] == Logger::ALERT || $record['level'] == Logger::EMERGENCY:
                return Logger::ERROR <= $this->level && Logger::EMERGENCY >= $this->level;
                break;
            default:
                return Logger::INFO <= $this->level && Logger::WARNING >= $this->level;
        }
    }

    protected function write(array $record): void
    {
        $record['formatted'] .= '||Trace-Id::' . Server::getTraceId();
        $record['formatted'] = str_replace(["\r", "\n"], '', $record['formatted'] . '');
        $record['formatted'] .= "\n";
        parent::write($record);
    }
}
