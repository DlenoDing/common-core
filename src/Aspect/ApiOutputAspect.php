<?php

namespace App\Aspect;

use App\Tools\ApiServer;
use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Response;
use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Tools\Check\CheckVal;
use Dleno\CommonCore\Tools\Crypt\OpenSslCrypt;
use Dleno\CommonCore\Tools\Output\ApiOutLog;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Utils\Context;
use Psr\Http\Message\ResponseInterface;

/**
 * @Aspect
 */
class ApiOutputAspect extends AbstractAspect
{
    // 要切入的类，可以多个，亦可通过 :: 标识到具体的某个方法，通过 * 可以模糊匹配
    public $classes = [
    ];

    // 要切入的注解，具体切入的还是使用了这些注解的类，仅可切入类注解和类方法注解
    public $annotations = [
        \Dleno\CommonCore\Annotation\OutputNotFormat::class,
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // 在调用前进行某些处理
        Context::set(RequestConf::OUTPUT_NOT_FORMAT, true);//输出不自动转换
        $result = $proceedingJoinPoint->process();
        // 在调用后进行某些处理

        return $result;
    }
}
