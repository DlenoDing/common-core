<?php

namespace Dleno\CommonCore\Websocket\Support;

use Dleno\CommonCore\Tools\Client;
use Hyperf\HttpServer\Response;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;

class WsOutLog
{
    /**
     * 输出接口日志
     * @param $result
     */
    public static function writeLog($result, $channel = null, $group = null)
    {
        if ($result instanceof ResponseInterface) {
            /** @var Response $result */
            $result = $result->getBody()
                             ->getContents();
        }
        //协程内执行
        Coroutine::create(
            function () use ($result, $channel, $group) {
                $result  = is_array($result) ? array_to_json($result) : $result;
                $result  = str_replace(PHP_EOL, '\n', $result);
                $result  = str_replace("\r", '', $result);
                $request = ApplicationContext::getContainer()
                                             ->get(ServerRequestInterface::class);

                $post                    = $request->getParsedBody();
                $headers                 = $request->getHeaders();
                $reqId                   = Context::get(RequestConf::REQUEST_REQ_ID, '0');
                $headers['Client-ReqId'] = $reqId;

                $allowHeaders   = config('app.ac_allow_headers', []);
                $allowHeaders[] = 'Client-ReqId';
                array_walk($allowHeaders, function (&$val) {
                    $val = strtolower($val);
                });
                $filterHeaders = config('app.filter_headers', [
                    'content-type', 'client-key', 'client-timestamp', 'client-nonce', 'client-sign', 'client-accesskey',
                ]);
                array_walk($filterHeaders, function (&$val) {
                    $val = strtolower($val);
                });
                $allowHeaders = array_diff($allowHeaders, $filterHeaders);
                foreach ($headers as $key => $val) {
                    unset($headers[$key]);
                    $key = strtolower($key);
                    if (in_array($key, $allowHeaders)) {
                        $headers[$key] = is_array($val) ? join('; ', $val) : $val;
                    }
                }

                $server = config('app_name') . '(' . Server::getIpAddr() . ')';
                //用 has() 判定是否走过消息路由(decode 设过 reqId),而非 if($reqId)——后者对合法的 reqId='0'/0 会误判为 false 落到 path 分支。
                if (Context::has(RequestConf::REQUEST_REQ_ID)) {
                    $mca     = Server::getRouteMca();
                    $service = join('\\', $mca['module']) . '\\' . $mca['ctrl'] . '->' . $mca['action'];
                } else {
                    $service = $request->path();
                }

                $channel  = $channel ?? Logger::API_CHANNEL_RESPONSE;
                $clientIp = Client::getIP();
                Logger::apiLog($channel, $group)
                      ->info(
                          sprintf(
                              'Server::%s||Ip::%s||Url::%s||Header::%s||Post::%s||Response::%s',
                              $server,
                              $clientIp,
                              $service,
                              array_to_json($headers),
                              array_to_json($post),
                              $result
                          ),
                          Server::runData()
                      );
            }
        );
    }
}