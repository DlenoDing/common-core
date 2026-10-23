<?php

namespace Dleno\CommonCore\Logger;

use Dleno\CommonCore\Tools\Server;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;

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

    /**
     * 写入流程按 monolog 3 RotatingFileHandler::write + StreamHandler::write 的语义自行实现(轮转、按 inode 重开、写失败关闭重开后重试)，
     * 区别只在错误捕获方式：
     * monolog 在 mkdir/fopen/fwrite 前后 set_error_handler/restore_error_handler，而错误处理器栈是进程级的；
     * Swoole 文件 hook 下这些调用会让出协程，让出期间其他协程的 warning 会落进 monolog 的临时处理器被吞掉，
     * 多个协程交错压栈/出栈还会把处理器栈弄乱。
     * 这里不碰处理器栈：调用一律加 @(Swoole 按协程保存 error_reporting，屏蔽不会波及其他协程)，按返回值判断成败。
     */
    protected function write(LogRecord $record): void
    {
        $record->formatted .= '||Trace-Id::' . Server::getTraceId();
        $record->formatted = str_replace(["\r", "\n"], '', $record->formatted . '');
        $record->formatted .= "\n";

        //轮转判定(同 RotatingFileHandler::write)
        if (null === $this->mustRotate) {
            $this->mustRotate = null === $this->url || !file_exists($this->url);
        }
        if ($this->nextRotation <= $record->datetime) {
            $this->mustRotate = true;
            $this->close(); // triggers rotation
        }

        $this->writeToStream($record, 0);

        if (true === $this->mustRotate) {
            $this->close(); // triggers rotation
        }
    }

    /** 已打开文件的 inode：文件被外部删除/改名后 inode 变化，需关闭重开 */
    private ?int $openedInode = null;

    /** 写失败(含句柄被其他协程关闭)后关闭重开、重新写入的最大次数 */
    private const MAX_WRITE_RETRIES = 3;

    /**
     * 重试次数按本次调用计数，不用实例级标志：同一 handler 被多个协程并发使用，
     * 一个协程在重试时不能影响其他协程的判断。
     */
    private function writeToStream(LogRecord $record, int $retries): void
    {
        if (is_resource($this->stream) && $retries === 0
            && $this->openedInode !== null && $this->openedInode !== $this->currentInode()) {
            $this->close();
        }
        if (!is_resource($this->stream)) {
            $this->openStream($record);
        }

        $stream = $this->stream;
        if ($this->useLocking && is_resource($stream)) {
            @flock($stream, LOCK_EX);
        }
        $error = $this->writeAll($stream, (string) $record->formatted);
        if ($this->useLocking && is_resource($stream)) {
            @flock($stream, LOCK_UN);
        }

        if ($error !== null) {
            if ($retries < self::MAX_WRITE_RETRIES) {
                //句柄已被其他协程关闭(轮转/重开)时不能再关当前的新句柄，只在仍是本句柄时关闭
                if ($this->stream === $stream) {
                    $this->close();
                }
                $this->writeToStream($record, $retries + 1);

                return;
            }

            throw new \UnexpectedValueException('Writing to the log file failed: ' . $error . Utils::getRecordMessageForException($record));
        }
    }

    private function openStream(LogRecord $record): void
    {
        for ($attempt = 0; ; $attempt++) {
            $url = $this->url;
            if (null === $url || '' === $url) {
                throw new \LogicException('Missing stream url, the stream can not be opened. This may be caused by a premature call to close().' . Utils::getRecordMessageForException($record));
            }

            $dir = dirname(str_starts_with($url, 'file://') ? substr($url, 7) : $url);
            if (!str_contains($url, '://') || str_starts_with($url, 'file://')) {
                if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                    throw new \UnexpectedValueException(sprintf('There is no existing directory at "%s" and it could not be created: %s', $dir, $this->lastError()));
                }
            }

            $stream = @fopen($url, $this->fileOpenMode);
            if ($this->filePermission !== null) {
                @chmod($url, $this->filePermission);
            }
            //失败时不动共享句柄：让出期间其他协程可能已打开成功
            if (!is_resource($stream)) {
                throw new \UnexpectedValueException(sprintf('The stream or file "%s" could not be opened in append mode: %s', $url, $this->lastError()) . Utils::getRecordMessageForException($record));
            }
            //mkdir/fopen 让出期间其他协程完成了轮转(文件名已变)：本次打开的是过期文件，丢弃；
            //新文件已被打开则直接沿用，否则按新文件名重新打开
            if ($this->url !== $url) {
                @fclose($stream);
                if (is_resource($this->stream)) {
                    return;
                }
                if ($attempt < self::MAX_WRITE_RETRIES) {
                    continue;
                }

                throw new \UnexpectedValueException('The log file was rotated repeatedly while being opened' . Utils::getRecordMessageForException($record));
            }
            //让出期间其他协程已打开同一文件：沿用已有句柄，关闭本次多开的
            if (is_resource($this->stream)) {
                @fclose($stream);

                return;
            }
            stream_set_chunk_size($stream, $this->streamChunkSize);
            $this->stream      = $stream;
            $this->openedInode = $this->currentInode();

            return;
        }
    }

    /**
     * 写完整条记录(非阻塞流可能只写入一部分，循环直到写完)；失败返回错误描述，成功返回 null。
     * @param resource $stream
     */
    private function writeAll($stream, string $data): ?string
    {
        $length  = strlen($data);
        $written = 0;
        while ($written < $length) {
            //fwrite 让出期间其他协程可能已关闭该句柄(轮转/重开)，对已关闭的句柄调用 fwrite 会抛 TypeError
            if (!is_resource($stream)) {
                return 'stream closed';
            }
            $result = @fwrite($stream, substr($data, $written));
            if ($result === false) {
                return 'fwrite failed: ' . $this->lastError();
            }
            if ($result === 0) {
                if (!is_resource($stream)) {
                    return 'stream closed';
                }
                $read  = $except = [];
                $write = [$stream];
                if (@stream_select($read, $write, $except, 1) === false) {
                    return 'stream_select failed: ' . $this->lastError();
                }
                continue;
            }
            $written += $result;
        }

        return null;
    }

    private function currentInode(): ?int
    {
        if ($this->url === null || str_starts_with($this->url, 'php://')) {
            return null;
        }
        clearstatcache(true, $this->url);
        $inode = @fileinode($this->url);

        return $inode === false ? null : $inode;
    }

    /** 最近一次错误描述(仅用于排查；error_get_last 为进程级，协程交错时可能不是本次调用的错误) */
    private function lastError(): string
    {
        $error = error_get_last();

        return $error === null ? 'unknown error' : (string) preg_replace('{^(fopen|mkdir|fwrite)\(.*?\): }', '', $error['message']);
    }

    public function close(): void
    {
        parent::close();
        $this->openedInode = null;
    }

    /**
     * 同 RotatingFileHandler::rotate()(更新文件名 + 按 maxFiles 清理旧文件及空目录)，
     * 区别是清理旧文件时不 set_error_handler，用 @ 屏蔽(多进程同时清理时 unlink 可能失败，忽略即可)。
     */
    protected function rotate(): void
    {
        $this->url          = $this->getTimedFilename();
        $this->nextRotation = $this->getNextRotation();
        $this->mustRotate   = false;

        if (0 === $this->maxFiles) {
            return;
        }
        //findRotatedFiles() 自 monolog 3.12 起才有；包依赖为 ^3.10，3.10/3.11 回落到原来的 glob 方式
        $logFiles = method_exists($this, 'findRotatedFiles')
            ? $this->findRotatedFiles()
            : (glob($this->getGlobPattern()) ?: []);
        if ($this->maxFiles >= count($logFiles)) {
            return;
        }
        usort($logFiles, static fn ($a, $b) => strcmp($b, $a));

        $basePath = self::normalizeSeparators(dirname($this->filename));
        foreach (array_slice($logFiles, $this->maxFiles) as $file) {
            if (!is_writable($file)) {
                continue;
            }
            @unlink($file);
            $dir = self::normalizeSeparators(dirname($file));
            while ($dir !== $basePath) {
                $entries = @scandir($dir);
                if ($entries === false || count(array_diff($entries, ['.', '..'])) > 0) {
                    break;
                }
                @rmdir($dir);
                $dir = self::normalizeSeparators(dirname($dir));
            }
        }
    }

    /** 同 monolog：仅 Windows 统一分隔符(POSIX 下反斜杠是合法文件名字符) */
    private static function normalizeSeparators(string $path): string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return $path;
        }

        return str_replace('\\', '/', $path);
    }
}
