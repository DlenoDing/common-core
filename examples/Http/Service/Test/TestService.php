<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Http\Service\Test;

use Dleno\CommonCore\Examples\Http\Component\TestComponent;
use Dleno\CommonCore\Examples\Http\Service\BaseService;
use Hyperf\HttpServer\Contract\RequestInterface;

class TestService extends BaseService
{
    public function test(array $params): array
    {
        $headers = get_inject_obj(RequestInterface::class)->getHeaders();
        $data    = get_inject_obj(TestComponent::class)->getData($params['key'] ?? 'test');

        return [
            'headers' => $headers,
            'data'    => $data->toArray(),
        ];
    }
}
