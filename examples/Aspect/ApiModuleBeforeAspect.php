<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Aspect;

use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Tools\ApiServer;
use Dleno\CommonCore\Tools\Check\CheckVal;

/**
 * API 模块前置切面示例。
 *
 * 启用方式:复制到业务项目后添加 #[\Hyperf\Di\Annotation\Aspect]。
 */
class ApiModuleBeforeAspect extends AppModuleBeforeAspect
{
    protected function isMatch(): bool
    {
        return !ApiServer::isAdminModule();
    }

    protected function checkAuth($whiteVal): void
    {
        if (CheckVal::checkInStatus(GlobalConf::WHITE_TYPE_TOKEN, $whiteVal)) {
            return;
        }

        // 示例:在这里按业务 token 体系校验 API 用户登录状态。
    }
}
