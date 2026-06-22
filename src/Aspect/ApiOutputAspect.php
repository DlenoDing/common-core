<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Aspect;

use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\ApiServer;
use Dleno\CommonCore\Tools\Check\CheckVal;
use Dleno\CommonCore\Tools\Crypt\OpenSslCrypt;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\OutPut;
use Dleno\CommonCore\Tools\Output\ApiOutLog;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Response;
use Psr\Http\Message\ResponseInterface;

use function Hyperf\Config\config;

/**
 * HTTP 接口输出切面。
 *
 * 切入 HTTP Controller 和异常日志注解方法，统一写响应日志，并在开启 API_DATA_CRYPT 时加密响应体。
 * 通过 common-core 的注解扫描自动注册，业务项目只需要提供 app 配置项和前置鉴权切面。
 */
#[Aspect]
class ApiOutputAspect extends AbstractAspect
{
    /**
     * 按类名切入的目标列表；当前使用 annotations 方式匹配 HTTP Controller。
     *
     * @var string[]
     */
    public array $classes = [];

    /**
     * 按注解切入的目标列表。
     *
     * @var string[]
     */
    public array $annotations = [
        \Hyperf\HttpServer\Annotation\AutoController::class,
        \Hyperf\HttpServer\Annotation\Controller::class,
        \Dleno\CommonCore\Annotation\ExceptionHandlerLog::class,
    ];

    /**
     * 执行 HTTP 响应日志和响应加密。
     *
     * @param ProceedingJoinPoint $proceedingJoinPoint AOP 连接点
     * @return mixed
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $result = $proceedingJoinPoint->process();

        if (Context::get(RequestConf::IN_HTTP_SERVER)) {
            ApiOutLog::writeLog($proceedingJoinPoint, $result);

            if (config('app.api_data_crypt')) {
                $whiteVal = ApiServer::getRouteVal();
                if (!((!Server::isProd() && get_header_val('Client-Debug')) || CheckVal::checkInStatus(
                    GlobalConf::WHITE_TYPE_ENCRYPT,
                    $whiteVal
                ))) {
                    if ($result instanceof ResponseInterface) {
                        /** @var Response $result */
                        $output = $result->getBody()
                            ->getContents();
                        try {
                            $output = OpenSslCrypt::encrypt($output, ApiServer::getClientAesKey());
                        } catch (\Throwable $e) {
                            Logger::systemLog('ENCRYPT')->warning($e->getMessage());
                            $output = OutPut::outJsonToError('Encrypt Error', RcodeConf::ERROR_SERVER);
                        }
                        $result = $result->withBody(new SwooleStream($output));
                    } else {
                        try {
                            $result = OpenSslCrypt::encrypt($result, ApiServer::getClientAesKey());
                        } catch (\Throwable $e) {
                            Logger::systemLog('ENCRYPT')->warning($e->getMessage());
                            $result = OutPut::outJsonToError('Encrypt Error', RcodeConf::ERROR_SERVER);
                        }
                    }
                }
            }
        }

        return $result;
    }
}
