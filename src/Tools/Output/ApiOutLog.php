<?php

namespace Dleno\CommonCore\Tools\Output;

use Dleno\CommonCore\Tools\Client;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Response;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiOutLog
{
    /**
     * 输出接口日志
     * @param $result
     */
    public static function writeLog(ProceedingJoinPoint $proceedingJoinPoint, $result, $channel = null, $group = null)
    {
        if (!(new \ReflectionMethod($proceedingJoinPoint->className, $proceedingJoinPoint->methodName))->isPublic()) {
            return;
        }
        if (Context::get(RequestConf::OUTPUT_NO_LOG)) {
            return;
        }
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
                $url     = $request->path();
                $post    = $request->getParsedBody();
                $headers = $request->getHeaders();

                $result = str_replace(PHP_EOL, '\n', $result);
                $result = str_replace("\r", '', $result);

                $allowHeaders = config('app.ac_allow_headers', []);
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

                $traceId = Server::getTraceId();
                $channel = $channel ?? Logger::API_CHANNEL_RESPONSE;
                $clientIp = Client::getIP();
                Logger::apiLog($channel, $group)
                      ->info(
                          sprintf(
                              'Server::%s||Trace-Id::%s||Ip::%s||Url::%s||Header::%s||Post::%s||Response::%s',
                              $server,
                              $traceId,
                              $clientIp,
                              $url,
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