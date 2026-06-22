<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Service;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

class BaseService
{
    #[Inject]
    protected Redis $redis;
}
