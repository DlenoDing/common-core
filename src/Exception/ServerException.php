<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Exception;

use Dleno\CommonCore\Tools\Output\ErrorOutLog;
use Hyperf\Server\Exception\ServerException as HyperfServerException;

class ServerException extends HyperfServerException
{
    private $throwData  = [];
    private $throwTrace = [];

    /**
     */
    public function __construct($message = "", $code = 0, array $throwData = [], array $throwTrace = [])
    {
        //ServerException是系统核心逻辑错误，不会把消息返回给客户端，所以不用翻译消息，只会写入日志文件
        parent::__construct($message, $code);

        $this->throwData  = $throwData;
        $this->throwTrace = $throwTrace;

        //系统错误日志
        ErrorOutLog::writeLog($this, ErrorOutLog::LOG_ERROR);
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
