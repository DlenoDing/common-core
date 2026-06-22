<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Aspect;

use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Tools\ApiServer;
use Dleno\CommonCore\Tools\Check\CheckVal;

/**
 * 后台模块前置切面示例。
 *
 * 启用方式:复制到业务项目后添加 #[\Hyperf\Di\Annotation\Aspect]。
 */
class AdminModuleBeforeAspect extends AppModuleBeforeAspect
{
    protected function isMatch(): bool
    {
        return ApiServer::isAdminModule();
    }

    protected function checkAuth($whiteVal): void
    {
        if (CheckVal::checkInStatus(GlobalConf::WHITE_TYPE_TOKEN, $whiteVal)) {
            return;
        }

        // 示例:在这里按业务后台登录体系校验管理员身份。
    }
}
