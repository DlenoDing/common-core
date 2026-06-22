<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\WebSocket\Controller\Test;

use Dleno\CommonCore\Examples\WebSocket\Controller\BaseController;
use Dleno\CommonCore\Examples\WebSocket\Service\Test\TestService;

/**
 * WS Controller 示例。
 *
 * 为避免 examples 被误扫后注册真实 WS 路由,这里不直接声明 #[WsController]。
 * 复制到业务 app/WebSocket/Controller 后,按需要添加:
 * #[\Dleno\CommonCore\Websocket\Annotation\WsController]
 */
class TestController extends BaseController
{
    public function index(): string
    {
        $this->checkParams([
            'userName' => 'required|max:50',
        ]);

        /** @var TestService $service */
        $service = $this->service;

        return $this->successData($service->index($this->request->post()));
    }
}
