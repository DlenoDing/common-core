<?php

namespace Dleno\CommonCore\Tools\Output;

use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Response;
use Hyperf\Context\Context;
use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;

class RpcOutLog
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
        if ($result instanceof ResponseInterface) {
            /** @var Response $result */
            $result = $result->getBody()
                             ->getContents();
            $result = json_to_array($result);
            $result = get_array_val($result, 'error');
            $result = $result ? array_to_json($result) : 'NULL';
        }
        //协程内执行
        Coroutine::create(
            function () use ($proceedingJoinPoint, $result, $channel, $group) {
                $result = is_array($result) ? array_to_json($result) : $result;
                //错误时输出路由对应的类和方法；正确时输出调用的类和方法
                if (strpos(
                        $proceedingJoinPoint->className,
                        'Exception'
                    ) !== false && $proceedingJoinPoint->methodName == 'handle'
                ) {
                    $mca     = Server::getRouteMca();
                    $service = join('\\', $mca['module']) . '\\' . $mca['ctrl'] . '->' . $mca['action'];
                } else {
                    $service = $proceedingJoinPoint->className . '->' . $proceedingJoinPoint->methodName;
                }
                $server = config('app_name') . '(' . Server::getIpAddr() . ')';
                $post   = $proceedingJoinPoint->arguments['keys'] ?? [];
                foreach ($post as $key => $value) {
                    if ($value instanceof \Dleno\CommonCore\JsonRpc\InterfaceBase\BaseParams) {
                        $post[$key] = $value->toArray();
                    }
                }
                $traceId = Server::getTraceId();
                $context = get_inject_obj(\Hyperf\Rpc\Context::class)->getData();
                $channel = $channel ?? Logger::API_CHANNEL_RESPONSE;
                Logger::apiLog($channel, $group)
                      ->info(
                          sprintf(
                              'Server::%s||Trace-Id::%s||Service::%s||Context::%s||Params::%s||Response::%s',
                              $server,
                              $traceId,
                              $service,
                              array_to_json($context),
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