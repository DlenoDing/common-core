<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Aspect;

use Dleno\CommonCore\Aspect\AbstractModuleBeforeAspect;

use function Hyperf\Config\config;

/**
 * 项目侧模块前置切面中间类示例。
 *
 * 复制到业务 app/Aspect 后,由具体子类决定是否添加 #[\Hyperf\Di\Annotation\Aspect] 启用。
 * examples 中不直接声明 #[Aspect],避免误扫后影响真实请求。
 */
abstract class AppModuleBeforeAspect extends AbstractModuleBeforeAspect
{
    protected function signPrefix(): string
    {
        return (string) config('app.sign_prefix', 'API_');
    }

    protected function signKey(): string
    {
        return (string) config('app.sign_key', '');
    }

    protected function signExpire(): int
    {
        return (int) config('app.sign_expire', 300);
    }
}
