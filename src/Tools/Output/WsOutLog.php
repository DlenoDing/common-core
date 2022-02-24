<?php

namespace Dleno\CommonCore\Tools\Output;

use Hyperf\HttpServer\Response;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
                $request = ApplicationContext::getContainer()
                                             ->get(ServerRequestInterface::class);

                $post                    = $request->getParsedBody();
                $headers                 = $request->getHeaders();
                $reqId                   = Context::get(RequestConf::REQUEST_REQ_ID, '0');
                $headers['Client-ReqId'] = $reqId;
                foreach ($headers as $key => $val) {
                    unset($headers[$key]);
                    $key = strtolower($key);
                    if (strpos($key, 'client-') !== false) {
                        $headers[$key] = is_array($val) ? join('; ', $val) : $val;
                    }
                }
                unset($headers['client-key'], $headers['client-timestamp'], $headers['client-nonce'], $headers['client-sign'], $headers['client-accesskey']);//去除不需要的key

                $server = config('app_name') . '(' . Server::getIpAddr() . ')';
                if ($reqId) {
                    $mca     = Server::getRouteMca();
                    $service = join('\\', $mca['module']) . '\\' . $mca['ctrl'] . '->' . $mca['action'];
                } else {
                    $service = $request->path();
                }

                $traceId = Server::getTraceId();
                $channel = $channel ?? Logger::API_CHANNEL_RESPONSE;
                Logger::apiLog($channel, $group)
                      ->info(
                          sprintf(
                              'Server::%s||Trace-Id::%s||Service::%s||Header::%s||Post::%s||Response::%s',
                              $server,
                              $traceId,
                              $service,
                              array_to_json($headers),
                              array_to_json($post),
                              $result
                          ),
                          self::runData()
                      );
            }
        );
    }

    /**
     * 获取资源消耗
     * @return array
     */
    private static function runData(): array
    {
        $return = [];
        // 显示运行时间
        $return['time'] = number_format((microtime(true) - Context::get(RequestConf::REQUEST_RUN_START)), 4) . 's';
        // 显示运行内存
        $return['memory'] = number_format(
                                (memory_get_usage() - Context::get(RequestConf::REQUEST_RUN_MEM)) / 1024
                            ) . 'kb';
        return $return;
    }
}