<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base;

use Dleno\CommonCore\Traits\LockCheckTrait;

/**
 * Class BaseCoreComponent
 * @package Dleno\CommonCore\Base
 */
class BaseCoreComponent
{
    use LockCheckTrait;//加锁/解锁/参数校验公共方法
}
