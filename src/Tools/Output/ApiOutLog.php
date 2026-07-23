<?php

namespace Dleno\CommonCore\Tools\Output;

use Dleno\CommonCore\Annotation\OutputLog;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Tools\Client;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Response;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

use function Hyperf\Config\config;

class ApiOutLog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $successAggregates = [];

    /**
     * 输出接口日志
     * @param $result
     */
    public static function writeLog(ProceedingJoinPoint $proceedingJoinPoint, $result, $channel = null)
    {
        $reflectionMethod = new ReflectionMethod($proceedingJoinPoint->className, $proceedingJoinPoint->methodName);
        if (!$reflectionMethod->isPublic()) {
            return;
        }
        if (Context::get(RequestConf::OUTPUT_NO_LOG)) {
            return;
        }
        $outputLog = self::getOutputLogAnnotation($reflectionMethod);
        if ($result instanceof ResponseInterface) {
            /** @var Response $result */
            $result = $result->getBody()
                             ->getContents();
        }
        //协程内执行
        Coroutine::create(
            function () use ($result, $channel, $outputLog, $proceedingJoinPoint) {
                $result  = self::stringifyLogValue($result);
                $request = ApplicationContext::getContainer()
                                             ->get(ServerRequestInterface::class);
                $url     = $request->path();
                $query   = $request->getQueryParams();
                $post    = $request->getParsedBody();
                $headers = $request->getHeaders();

                $result            = str_replace(PHP_EOL, '\n', $result);
                $result            = str_replace("\r", '', $result);
                $isSuccessResponse = self::isSuccessResponse($result);
                $post              = self::truncateLogBody(self::stringifyLogValue($post), $outputLog->maxPostLength);
                $result            = self::truncateLogBody($result, $outputLog->maxResponseLength);

                $allowHeaders = config('app.ac_allow_headers', []);
                array_walk($allowHeaders, function (&$val) {
                    $val = strtolower($val);
                });
                //敏感头过滤清单走业务 config('app.filter_headers')(便于排查时按需单独开关某个头);
                //包内仅给最小兜底默认,client-token/authorization/cookie 等由业务在 app.filter_headers 里配置。
                $filterHeaders = config('app.filter_headers', [
                    'content-type',
                    'client-key',
                    'client-timestamp',
                    'client-nonce',
                    'client-sign',
                    'client-accesskey',
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

                $channel  = $channel ?? Logger::API_CHANNEL_RESPONSE;
                $clientIp = Client::getIP();
                if ($outputLog->aggregateSuccess && $isSuccessResponse) {
                    self::writeAggregatedSuccessLog(
                        $channel,
                        $server,
                        $clientIp,
                        $url,
                        array_to_json($headers),
                        array_to_json($query),
                        $post,
                        $result,
                        $outputLog,
                        $proceedingJoinPoint
                    );
                    return;
                }
                Logger::apiLog($channel)
                      ->info(
                          sprintf(
                              'Server::%s||Ip::%s||Url::%s||Header::%s||Query::%s||Post::%s||Response::%s',
                              $server,
                              $clientIp,
                              $url,
                              array_to_json($headers),
                              array_to_json($query),
                              $post,
                              $result
                          ),
                          Server::runData()
                      );
            }
        );
    }

    private static function getOutputLogAnnotation(ReflectionMethod $method): OutputLog
    {
        $classAttributes = $method->getDeclaringClass()
                                  ->getAttributes(OutputLog::class);
        $methodAttributes = $method->getAttributes(OutputLog::class);

        if (!empty($methodAttributes)) {
            return $methodAttributes[0]->newInstance();
        }
        if (!empty($classAttributes)) {
            return $classAttributes[0]->newInstance();
        }
        return new OutputLog();
    }

    private static function stringifyLogValue($value): string
    {
        if (is_array($value)) {
            return array_to_json($value);
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return array_to_json((array)$value);
        }
        if (is_null($value)) {
            return '';
        }
        return (string)$value;
    }

    private static function truncateLogBody(string $body, ?int $maxLength): string
    {
        if (is_null($maxLength) || $maxLength < 0) {
            return $body;
        }

        $bytes = strlen($body);
        if ($bytes <= $maxLength) {
            return $body;
        }

        return substr($body, 0, max(0, $maxLength)) . sprintf(
            '...[truncated bytes=%d limit=%d]',
            $bytes,
            $maxLength
        );
    }

    private static function isSuccessResponse(string $result): bool
    {
        $data = json_decode($result, true);
        if (!is_array($data) || !array_key_exists('code', $data)) {
            return false;
        }
        return (int)$data['code'] === RcodeConf::SUCCESS;
    }

    private static function writeAggregatedSuccessLog(
        string $channel,
        string $server,
        string $clientIp,
        string $url,
        string $headers,
        string $query,
        string $post,
        string $result,
        OutputLog $outputLog,
        ProceedingJoinPoint $proceedingJoinPoint
    ): void {
        $now      = time();
        $interval = max(1, $outputLog->aggregateSuccessInterval);
        $minCount = max(1, $outputLog->aggregateSuccessMinCount);
        $key      = md5($channel . '|' . $proceedingJoinPoint->className . '|' . $proceedingJoinPoint->methodName . '|' . $url);

        if (!isset(self::$successAggregates[$key])) {
            self::$successAggregates[$key] = [
                'count'       => 0,
                'windowStart' => $now,
            ];
        }

        self::$successAggregates[$key]['count']++;
        $aggregate = self::$successAggregates[$key];
        if ($aggregate['count'] < $minCount && ($now - $aggregate['windowStart']) < $interval) {
            return;
        }

        unset(self::$successAggregates[$key]);
        Logger::apiLog($channel)
              ->info(
                  sprintf(
                      'Server::%s||Ip::%s||Url::%s||Header::%s||Query::%s||Post::%s||Response::%s',
                      $server,
                      $clientIp,
                      $url,
                      $headers,
                      $query,
                      $post,
                      array_to_json([
                          'aggregate_success' => true,
                          'count'             => $aggregate['count'],
                          'window_start'      => $aggregate['windowStart'],
                          'window_end'        => $now,
                          'class'             => $proceedingJoinPoint->className,
                          'method'            => $proceedingJoinPoint->methodName,
                          'last_response'     => $result,
                      ])
                  ),
                  Server::runData()
              );
    }
}
