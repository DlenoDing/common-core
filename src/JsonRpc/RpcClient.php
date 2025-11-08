<?php

namespace Dleno\CommonCore\JsonRpc;

/**
 * 服务client对象
 * Class RpcClient
 * @package Dleno\CommonCore\JsonRpc
 */
class RpcClient
{
    /**
     * @var string 服务类名
     */
    private $serviceClass;

    public function __construct(string $serviceClass)
    {
        $this->serviceClass = $serviceClass;
    }

    public function __call($method, $params)
    {
        //调用服务
        return get_inject_obj($this->serviceClass)->{$method}(...$params);
    }
}