<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Middleware;

use Dleno\CommonCore\Middleware\Http\DefaultModuleBeforeMiddleware;
use Dleno\CommonCore\Tools\ApiServer;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 项目侧模块前置中间件示例。
 *
 * common-core 会在 InitMiddleware 之后自动注册 AbstractModuleBeforeMiddleware。
 * 复制到业务项目后，把 namespace 改成 App\Middleware，并在 dependencies.php 中绑定：
 *
 * Dleno\CommonCore\Middleware\Http\AbstractModuleBeforeMiddleware::class
 *     => App\Middleware\AppModuleBeforeMiddleware::class,
 *
 * 本类继承包内默认实现，只覆写业务相关钩子：
 *  - checkAuth(): 登录校验，可在单类内按 ApiServer::isAdminModule() 分流 Admin / API。
 *  - checkReplay(): 防重放示例，默认关闭。
 *  - signPrefix()/signKey()/signExpire() 继续使用 DefaultModuleBeforeMiddleware 的 config('app.sign_*') 默认实现。
 */
class AppModuleBeforeMiddleware extends DefaultModuleBeforeMiddleware
{
    /**
     * 默认 Redis 客户端，供 checkReplay() 防重放占位使用。
     */
    #[Inject]
    protected Redis $redis;

    /**
     * 登录校验。
     *
     * 白名单 / 非正式环境 debug 的放行已由父类判定；进入本方法即表示当前请求需要业务鉴权。
     * $request 为解密后的请求，parsedBody 已就绪，可直接读取业务参数。
     */
    protected function checkAuth(ServerRequestInterface $request)
    {
        if (ApiServer::isAdminModule()) {
            // 后台登录校验接入点。复制到业务项目后替换成真实后台登录体系。
            /*
            $token = get_header_val('Client-Token', '');
            $checkAuth = get_inject_obj(AdminAccountComponent::class)->checkAuth($token);
            if (!$checkAuth) {
                throw new HttpException('Error Sign', RcodeConf::ERROR_TOKEN);
            }
            */
            return;
        }

        // API 端 token 校验接入点。复制到业务项目后替换成真实用户登录体系。
        /*
        $token = get_header_val('Client-Token', '');
        $checkAuth = get_inject_obj(AccountComponent::class)->checkAuth($token);
        if (!$checkAuth) {
            throw new HttpException('Error Sign', RcodeConf::ERROR_TOKEN);
        }
        */
    }

    /**
     * 防重放校验示例，默认关闭。
     *
     * 返回值约定：true = 放行；false = 判定为重放，由父类统一抛签名错误。
     * 如需启用，删除开头的 return true;，并确认 Redis 可用。
     */
    protected function checkReplay(ServerRequestInterface $request): bool
    {
        // 默认不启用防重放；删除本行即可启用下面的示例实现。
        return true;

        $sign = (string) get_header_val('Client-Sign', '');
        if ($sign === '') {
            return true;
        }

        // 覆盖时间戳允许窗口 [now - signExpire, now + signExpire]。
        $ttl = max(1, $this->signExpire() * 2);
        $key = $this->signPrefix() . 'replay:' . $sign;

        // SET NX EX: 首次占位成功放行；窗口内重复签名占位失败，即判定为重放。
        return $this->redis->set($key, '1', ['NX', 'EX' => $ttl]) !== false;
    }
}
