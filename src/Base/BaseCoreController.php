<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\ServerException;
use Dleno\CommonCore\Tools\OutPut;
use Dleno\CommonCore\Traits\LockCheckTrait;
use Psr\Container\ContainerInterface;

class BaseCoreController
{
    use LockCheckTrait;//加锁/解锁/参数校验公共方法

    #[Inject]
    protected ContainerInterface $container;

    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    /**
     * 对应服务
     */
    protected $service;

    public function __construct()
    {
        $serviceClass = '\\' . str_replace('Controller', 'Service', static::class);
        if (class_exists($serviceClass)) {
            $this->service = ApplicationContext::getContainer()->get($serviceClass);
        } else {
            throw new ServerException('Class Not Exists::' . $serviceClass);
        }

        //此处不能写接口鉴权校验或用户登录信息校验(需要每次执行action方法时的逻辑都不能写在里面)
        //此对象在同一进程里只会执行一次__construct方法
    }

    /**
     * 返回data结果
     * @param array|null $data
     * @param string $msg
     * @return string
     */
    protected function successData(?array $data = null, $msg = '')
    {
        $ret         = RcodeConf::$dftReturn;
        $ret['data'] = $data ?? [];
        $ret['msg']  = $msg;
        $ret['code'] = RcodeConf::SUCCESS;
        return OutPut::outJsonToData($ret);
    }
}
