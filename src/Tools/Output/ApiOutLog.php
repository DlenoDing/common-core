<?php

namespace Dleno\CommonCore\Tools\Output;

use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Response;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Context;
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
    public static function writeLog(ProceedingJoinPoint $proceedingJoinPoint, $result)
    {
        if ((new \ReflectionMethod($proceedingJoinPoint->className, $proceedingJoinPoint->methodName))->isPrivate()) {
            return;
        }
        if ($result instanceof ResponseInterface) {
            /** @var Response $result */
            $result = $result->getBody()
                             ->getContents();
        }
        //协程内执行
        Coroutine::create(
            function () use ($result) {
                $result  = is_array($result) ? array_to_json($result) : $result;
                $request = ApplicationContext::getContainer()
                                             ->get(ServerRequestInterface::class);
                $url     = $request->path();
                $post    = $request->getParsedBody();
                $headers = $request->getHeaders();
                $postRaw = $request->getBody()
                                   ->getContents();
                $postRaw = str_replace(PHP_EOL, '\n', $postRaw);
                $postRaw = str_replace("\r", '\r', $postRaw);

                foreach ($headers as $key => $val) {
                    unset($headers[$key]);
                    $key = strtolower($key);
                    if (strpos($key, 'client-') !== false) {
                        $headers[$key] = is_array($val) ? join('; ', $val) : $val;
                    }
                }
                unset($headers['client-key'], $headers['client-timestamp'], $headers['client-nonce'], $headers['client-sign'], $headers['client-accesskey']);//去除不需要的key

                $server = config('app_name') . '(' . Server::getIpAddr() . ')';

                $traceId = Server::getTraceId();
                Logger::apiLog(Logger::API_CHANNEL_RESPONSE)
                      ->info(
                          sprintf(
                              'Server::%s||Trace-Id::%s||Url::%s||Header::%s||PostRaw::%s||Post::%s||Response::%s',
                              $server,
                              $traceId,
                              $url,
                              array_to_json($headers),
                              $postRaw,
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