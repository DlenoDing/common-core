<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 方法级:声明某个 AutoController 方法允许的 HTTP 请求方式（覆盖类级 / 全局默认），优先级最高。
 *
 * 例：#[AllowMethods(['POST'])] —— 该方法只接受 POST；GET 写在里面时框架会自动补 HEAD。
 * 取值为「空」（空数组 / 空字符串 / 全空白）时视为未声明，按下一优先级回退（类 AutoController.defaultMethods → config）。
 * OPTIONS 预检始终由 InitMiddleware 全局处理，无需在此声明。
 *
 * 解析优先级见 {@see \Dleno\CommonCore\Core\Route\RouterDispatcherFactory::resolveMethods()}。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AllowMethods extends AbstractAnnotation
{
    /**
     * @param string[]|string $methods 允许的请求方式；数组或逗号分隔字符串均可（大小写不限）
     */
    public function __construct(public array|string $methods = [])
    {
    }
}
