<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Http\Controller;

use Dleno\CommonCore\Base\BaseCoreController;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\Client;
use Dleno\CommonCore\Tools\Server;

/**
 * HTTP 基础 Controller 示例。
 *
 * 复制到业务项目时把 namespace 改为 App\Controller。
 */
class BaseController extends BaseCoreController
{
    /**
     * 示例:按当前路由 + 设备号限制同一设备并发访问。
     * @throws HttpException
     */
    protected function lockThread(int $expire = 5): bool
    {
        $mca           = Server::getRouteMca();
        $mca['module'] = implode('_', $mca['module']);
        $mca           = implode('_', $mca);
        $device        = (string) Client::getDevice();
        $hashKey       = 'Thread_' . $mca . '_' . $device;

        if (!$this->lock($hashKey, $device, $expire)) {
            throw new HttpException('Access Frequency Limit', RcodeConf::ERROR_SERVER);
        }
        return true;
    }
}
