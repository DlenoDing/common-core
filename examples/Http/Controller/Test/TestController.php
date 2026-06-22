<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Http\Controller\Test;

use Dleno\CommonCore\Examples\Http\Controller\BaseController;
use Dleno\CommonCore\Examples\Http\Service\Test\TestService;

/**
 * HTTP Controller 示例。
 *
 * 为避免 examples 被误扫后注册真实 HTTP 路由,这里不直接声明 #[AutoController]。
 * 复制到业务 app/Controller/Test 后,按需要添加:
 * #[\Hyperf\HttpServer\Annotation\AutoController]
 */
class TestController extends BaseController
{
    public function test(): string
    {
        $post = $this->request->post();
        $this->checkParams(
            [
                'uid'   => 'required|integer|gt:0',
                'phone' => 'string',
                'email' => 'email',
            ],
            $post
        );

        /** @var TestService $service */
        $service = $this->service;

        return $this->successData($service->test($post));
    }
}
