<?php

namespace Dleno\CommonCore\Logger;

use Dleno\CommonCore\Tools\Server;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
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
        $record->formatted .= "\n";
        parent::write($record);
    }

    /**
     * 父类 write() 紧接着压入 monolog 临时错误处理器后调用本方法，返回后再弹出。
     * Swoole 文件 hook 下 fwrite 会让出协程，而错误处理器栈是进程级的：让出期间其他协程的 warning
     * 会被该临时处理器吞掉（不再转为 ErrorException），并污染本次写入结果、引发误重试或误报写入失败。
     * 写入期间先弹出临时处理器、写完压回占位（两处均无协程切换），让出期间栈顶保持为全局处理器；
     * 写入错误仍按 monolog 原逻辑记入 errorMessage，由父类关闭重开并重试一次。
     */
    protected function streamWrite($stream, LogRecord $record): void
    {
        restore_error_handler();
        $error = null;
        try {
            parent::streamWrite($stream, $record);
        } catch (\ErrorException $e) {
            $error = $e->getMessage();
        } finally {
            set_error_handler(static fn (): bool => true);
        }
        if ($error !== null) {
            static $errorMessage = null;
            $errorMessage ??= new \ReflectionProperty(StreamHandler::class, 'errorMessage');
            $errorMessage->setValue($this, preg_replace('{^(fopen|mkdir|fwrite)\(.*?\): }', '', $error));
        }
    }
}
