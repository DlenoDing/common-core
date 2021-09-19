<?php

namespace PHPSTORM_META {

    // Reflect
    override(\Psr\Container\ContainerInterface::get(0), map('@'));
    override(\Hyperf\Utils\Context::get(0), map('@'));
    override(\Hyperf\WebSocketServer\Context::get(0), map('@'));
    override(\make(0), map('@'));
    override(\di(0), map('@'));
    override(\get_inject_obj(0), map('@'));
    override(\rpc_service_get(0), map('@'));
    override(\dynamic_rpc_service_get(0, 1), type(1));
    override(\Dleno\RpcTcc\Transaction::getService(0), map('@'));

}