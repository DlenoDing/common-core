<?php


namespace Dleno\CommonCore\Core\Request;


class Request extends \Hyperf\HttpServer\Request
{
    public static  $XRealScheme = 'XRealScheme';

    public function getScheme()
    {
        if ($this->hasHeader(self::$XRealScheme)) {
            return $this->header(self::$XRealScheme);
        }
        return $this->getUri()->getScheme();
    }
}