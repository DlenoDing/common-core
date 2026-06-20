<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Websocket\Process;

use Dleno\CommonCore\Tools\Websocket\WsKeys;
use Dleno\CommonCore\Tools\Websocket\WsServerComponent;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;

/**
 * WS 服务器注册进程基类（纯基建，下沉自脚手架）。
 *
 * 周期性 registerServer 续约本机在线注册（休眠半个 REG 超时基数，保证不过期）。
 * 业务侧用子类 extends 之，并补上 #[Process] 注解（供 Hyperf 扫描注册）+ isEnable（部署门禁，
 * 如 ENABLE_WS/app_env）。注册逻辑/续约节奏由本基类锁定。
 */
abstract class AbstractWsServerProcess extends AbstractProcess
{
    public string $name = 'WebSocketServerProcess';

    public function handle(): void
    {
        while (ProcessManager::isRunning()) {
            //服务器注册
            get_inject_obj(WsServerComponent::class)->registerServer();
            //休眠一半服务器的超时时间
            sleep(intval(WsKeys::SERVER_REG_LIMIT / 2));
        }
    }
}
