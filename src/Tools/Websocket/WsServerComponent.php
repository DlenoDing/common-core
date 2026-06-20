<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Websocket;

use Dleno\CommonCore\Base\BaseCoreComponent;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * WS 服务器/客户端注册表（纯基建，下沉自脚手架）。
 * - 服务器注册/在线列表（跨服可见，按 REG 超时过滤）
 * - 本服务器在线 fd 注册表（Redis，供 CheckOnline/Close 枚举"全体"；与本机实时 FdCollector 双轨并存，职责不同）
 * - 下线服务器队列清理（UNLINK 固定 5 子键，无 KEYS/SCAN）
 * 全部 key/队列命名走 WsKeys（字节级兼容脚手架）。业务侧用空子类 extends 之即可。
 */
class WsServerComponent extends BaseCoreComponent
{
    #[Inject]
    protected Redis $redis;

    /**
     * 注册当前服务器
     */
    public function registerServer()
    {
        $server        = $this->getServerKey();
        $serverListKey = WsKeys::serverListKey();
        $timeout       = time() + WsKeys::SERVER_REG_LIMIT * 2;
        $this->redis->hSet($serverListKey, $server, strval($timeout));
        //服务器客户端列表缓存过期时间
        $clientListKey = $this->getClientsListKey();
        $this->redis->expire($clientListKey, WsKeys::SERVER_REG_LIMIT * 3);
    }

    /**
     * 获取当前在线服务器列表
     * @return array
     */
    public function getServerList()
    {
        $serverListKey = WsKeys::serverListKey();
        $servers       = $this->redis->hGetAll($serverListKey);
        $servers       = $servers ?: [];

        $now      = time();
        $offLines = [];
        foreach ($servers as $server => $time) {
            if ($now >= $time) {
                $offLines[] = $server;
                unset($servers[$server]);
            }
        }

        if (!empty($offLines)) {
            $this->redis->hDel($serverListKey, ...$offLines);
            $this->clearRelServerData($offLines);
        }

        $servers = array_keys($servers);
        return $servers;
    }

    /**
     * 清理下线服务器的关联数据（异步、UNLINK 固定 5 子键，无 KEYS/SCAN）
     * @param $offLines
     */
    public function clearRelServerData($offLines)
    {
        //后台异步清理(用 Coroutine::create 保留父协程 Context,避免裸 go() 丢上下文/吞异常)
        Coroutine::create(
            function () use ($offLines) {
                foreach ($offLines as $offLine) {
                    //下线服务器队列 = Hyperf AsyncQueue 通道,固定 5 子键,直接 UNLINK,无需扫库
                    $this->redis->unlink(...WsKeys::queueSubKeys($offLine));
                }
            }
        );
    }

    /**
     * 注册客户端
     * @param $fd
     */
    public function registerClient($fd)
    {
        $clientListKey = $this->getClientsListKey();
        $timeout       = time() + WsKeys::BIND_CACHE_TIME;//过期时间
        $this->redis->hSet($clientListKey, strval($fd), strval($timeout));
    }

    /**
     * 分页获取当前服务器的有效客户端 FD
     * @param int $cursor 初始必须是 NULL,才能从第一页获取
     * @param int $count
     * @return array
     */
    public function getClients(&$cursor = null, $count = 100)
    {
        $clientListKey = $this->getClientsListKey();
        //如果 Hash 的 F-V 对小于 512 且 V 较短,HSCAN 会全部返回
        $clients = $this->redis->hScan($clientListKey, $cursor, '', $count);
        $clients = $clients ?: [];

        $now      = time();
        $offLines = [];
        foreach ($clients as $client => $time) {
            if ($now >= $time) {
                $offLines[] = $client;
                unset($clients[$client]);
            }
        }

        if (!empty($offLines)) {
            $offLines = array_chunk($offLines, 50);
            foreach ($offLines as $offLine) {
                $this->redis->hDel($clientListKey, ...$offLine);
            }
        }

        $clients = array_keys($clients);
        return $clients;
    }

    /**
     * 删除客户端
     * @param $fd
     */
    public function delClient($fd)
    {
        $clientListKey = $this->getClientsListKey();
        $this->redis->hDel($clientListKey, strval($fd));
    }

    /**
     * 当前服务器的客户端列表缓存 KEY
     * @return string
     */
    private function getClientsListKey()
    {
        return WsKeys::fdsKey($this->getServerKey());
    }

    /**
     * 服务器身份键（IP，点改下划线）
     */
    public function getServerKey($server = null)
    {
        if (is_null($server)) {
            $server = Server::getIpAddr();
        }
        $server = str_replace('.', '_', $server);
        return $server;
    }
}
