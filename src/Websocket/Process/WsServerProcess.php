<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Process;

use Dleno\CommonCore\Websocket\Component\WsServerComponent;
use Dleno\CommonCore\Websocket\Support\WsKeys;
use Dleno\CommonCore\Websocket\Support\WsProcessSwitch;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Process\ProcessManager;

/**
 * WS 服务器注册进程（纯基建，归包锁死）。
 *
 * 周期性 registerServer 续约本机在线注册（休眠半个 REG 超时基数，保证不过期）。
 * 由 #[Process] 自动注册（common-core 注解扫描覆盖整个 src）；是否启动由 WsProcessSwitch 门禁
 * （ENABLE_WS + local 开关）控制，业务无需也不应继承本类——继承即可篡改核心注册逻辑。
 */
#[Process]
class WsServerProcess extends AbstractProcess
{
    public string $name = 'WebSocketServerProcess';

    public function handle(): void
    {
        while (ProcessManager::isRunning()) {
            //服务器注册:周期续约本机在线注册
            get_inject_obj(WsServerComponent::class)->registerServer();
            //休眠一半服务器的超时时间
            sleep(intval(WsKeys::SERVER_REG_LIMIT / 2));
        }
    }

    public function isEnable($server): bool
    {
        return WsProcessSwitch::shouldRun();
    }
}
