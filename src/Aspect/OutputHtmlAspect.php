<?php

namespace Dleno\CommonCore\Aspect;

use Dleno\CommonCore\Conf\RequestConf;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;

#[Aspect]
class OutputHtmlAspect extends AbstractAspect
{
    // 要切入的类，可以多个，亦可通过 :: 标识到具体的某个方法，通过 * 可以模糊匹配
    public array $classes = [
    ];

    // 要切入的注解，具体切入的还是使用了这些注解的类，仅可切入类注解和类方法注解
    public array $annotations = [
        \Dleno\CommonCore\Annotation\OutputHtml::class,
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // 切面切入后，执行对应的方法会由此来负责
        // $proceedingJoinPoint 为连接点，通过该类的 process() 方法调用原方法并获得结果
        // 在调用前进行某些处理
        //不自动驼峰转换
        Context::set(RequestConf::OUTPUT_HTML, true);

        $result = $proceedingJoinPoint->process();
        // 在调用后进行某些处理
        $response = Context::get(ResponseInterface::class);
        // 设置返回数据格式及编码
        $response = $response->withoutHeader('Content-Type')
                             ->withHeader('Content-Type', 'text/html; charset=utf-8');
        Context::set(ResponseInterface::class, $response);

        return $result;
    }

}
