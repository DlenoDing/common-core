<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;

/**
 * 模块前置中间件的包内默认实现：为 AbstractModuleBeforeMiddleware 的钩子契约提供默认实现。
 *
 * ConfigProvider 默认把 AbstractModuleBeforeMiddleware 绑定到本类，作为「业务未接管时」的安全默认：
 * 签名/数据解密按 config 开关执行，checkAuth 为 no-op（不鉴权放行），checkReplay 放行，sign* 读 config('app.sign_*')。
 *
 * 业务接管：写一个【继承本类】的子类，只覆写需要的钩子（其余走本类默认），
 * 并在 app config/autoload/dependencies.php 把 AbstractModuleBeforeMiddleware 绑定到自己的子类即可（覆盖本默认）。
 */
class DefaultModuleBeforeMiddleware extends AbstractModuleBeforeMiddleware
{
    /**
     * 默认登录校验：no-op（不鉴权放行）。业务覆写实现实际校验。
     */
    protected function checkAuth(ServerRequestInterface $request)
    {
    }

    /**
     * 默认防重放：放行（不拦截）。业务覆写返回 false 即判定为重放（典型用 Client-Sign 做 Redis SET NX 占位）。
     */
    protected function checkReplay(ServerRequestInterface $request): bool
    {
        return true;
    }

    /**
     * 签名前缀（读 config('app.sign_prefix')；包内不设业务默认值，仅以空串兜底避免漏配出错，默认值由业务配置提供）。
     */
    protected function signPrefix(): string
    {
        return (string) config('app.sign_prefix', '');
    }

    /**
     * 签名密钥（默认读 config('app.sign_key')）。
     */
    protected function signKey(): string
    {
        return (string) config('app.sign_key', '');
    }

    /**
     * 签名允许的时间偏移量（秒，默认读 config('app.sign_expire')）。
     */
    protected function signExpire(): int
    {
        return (int) config('app.sign_expire', 300);
    }
}
