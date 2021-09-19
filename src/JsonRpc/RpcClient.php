<?php

namespace Dleno\CommonCore\JsonRpc;

use Dleno\CommonCore\Exception\AppException;
use Hyperf\JsonRpc\ResponseBuilder;
use Hyperf\RpcClient\Exception\RequestException;

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

    public function __call(string $method, array $params)
    {
        try {
            //调用服务
            $result = get_inject_obj($this->serviceClass)->{$method}(...$params);
        } catch (\Throwable $throwable) {
            //这里无法区分返回的错误是本地抛出还是远程对端抛出，则不做处理
            /*if ($throwable instanceof RequestException) {
                if ($throwable->getCode() == ResponseBuilder::SERVER_ERROR && $throwable->getThrowableClassName(
                    ) == AppException::class) {
                    $throwable = new AppException($throwable->getThrowableMessage(), $throwable->getThrowableCode());
                }
            }*/
            //错误抛出
            throw $throwable;
        }

        return $result;
    }
}