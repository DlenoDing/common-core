<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Base;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\ServerException;
use Dleno\CommonCore\Tools\Check\CheckParams;
use Dleno\CommonCore\Tools\Lock\DcsLock;
use Dleno\CommonCore\Tools\OutPut;
use Psr\Container\ContainerInterface;

class BaseCoreController
{
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
            if (!ApplicationContext::getContainer()
                                   ->has($serviceClass)) {
                ApplicationContext::getContainer()
                                  ->set($serviceClass, new $serviceClass());
            }
            $this->service = ApplicationContext::getContainer()
                                               ->get($serviceClass);
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

    /**
     * 加锁（使用分布式锁时$uuid建议使用雪花算法）
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @param int $time 锁持有时间
     * @param int $timeout 请求锁超时时间(<0:不超时；=0:未获取到锁则直接失败；>0:未获取到锁则抢占式继续获取锁，直到超时)
     * @return bool
     */
    protected function lock(string $lockKey, string $uuid, int $time = 3, int $timeout = 0): bool
    {
        $time = $time <= 0 ? 3 : $time;//不允许持有时间永久，避免极端情况造成死锁
        return DcsLock::lock($lockKey, $uuid, $time, $timeout);
    }

    /**
     * 解锁
     * @param string $lockKey 锁key
     * @param string $uuid 请求加锁的唯一标识（并发时保证每个请求标识唯一）
     * @return bool
     */
    protected function unlock(string $lockKey, string $uuid): bool
    {
        return DcsLock::unlock($lockKey, $uuid);
    }

    /**
     * 执行接口参数校验
     * @param array $rules 规则详见：https://hyperf.wiki/2.0/#/zh-cn/validation
     * @param array $params
     * @param array $customAttributes
     * @param array $messages
     */
    protected function checkParams(array $rules, $params = [], array $customAttributes = [], array $messages = [])
    {
        //默认接收post参数
        if (empty($params)) {
            $params = $this->request->post();
        }
        return CheckParams::check($rules, $params, $customAttributes, $messages);
    }
}
