<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Conf;

class WsRequestConf
{
    public const REQUEST_ACCOUNT_INFO = '__ACCOUNT_INFO__';
    public const REQUEST_ACCOUNT_ID   = '__ACCOUNT_ID__';

    public const REQUEST_HEADER_DEBUG      = 'Client-Debug';
    public const REQUEST_HEADER_TOKEN      = 'Client-Token';
    public const REQUEST_HEADER_ACCOUNT_ID = 'Client-AccountId';
    public const REQUEST_HEADER_DEVICE     = 'Client-Device';
}
