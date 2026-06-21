<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception;

use Dleno\CommonCore\Tools\Language;
use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Hyperf\Server\Exception\ServerException as HyperfServerException;

class AppException extends HyperfServerException
{
    private $throwData  = [];
    private $throwTrace = [];

    public function __construct($message = "", $code = 0, array $throwData = [], array $throwTrace = [], ?\Throwable $previous = null)
    {
        //翻译消息
        $message = $message ? Language::get($message) : '';
        //$previous:重包装时挂上原始异常(类型/code/堆栈),由 ErrorOutLog 遍历记入日志
        parent::__construct($message, $code, $previous);

        $this->throwData  = $throwData;
        $this->throwTrace = $throwTrace;

        //系统错误日志
        ErrorOutLog::writeLog($this, ErrorOutLog::LOG_DEBUG, false);
    }

    public function getThrowData()
    {
        return $this->throwData;
    }

    public function getThrowTrace()
    {
        return $this->throwTrace;
    }
}
