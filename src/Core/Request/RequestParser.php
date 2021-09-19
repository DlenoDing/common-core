<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Core\Request;

use Hyperf\HttpMessage\Server\Request\Parser;

class RequestParser extends Parser
{
    public function parse(string $rawBody, string $contentType): array
    {
        //屏蔽加密后解密失败的错误，解密放在切面里处理，此处无法获取路由信息
        foreach ($this->parsers as $key => $parser) {
            $parser->throwException = false;
            $this->parsers[$key]    = $parser;
        }
        return parent::parse($rawBody, $contentType);
    }
}
