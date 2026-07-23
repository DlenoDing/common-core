<?php

namespace Dleno\CommonCore\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 单独配置接口输出日志行为。
 *
 * 该注解只影响写入日志的 Post/Response 文本或成功日志聚合,不改变接口响应内容和默认日志格式。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class OutputLog extends AbstractAnnotation
{
    /**
     * @param int|null $maxPostLength 写入日志的 Post 最大字节数;null 或负数表示不裁剪,0 表示只保留裁剪说明。
     * @param int|null $maxResponseLength 写入日志的 Response 最大字节数;null 或负数表示不裁剪,0 表示只保留裁剪说明。
     * @param bool $aggregateSuccess 是否聚合成功响应日志;默认关闭,只对 code=0 的 JSON 响应生效。
     * @param int $aggregateSuccessInterval 成功日志聚合最长窗口秒数。
     * @param int $aggregateSuccessMinCount 成功日志聚合最小条数。
     */
    public function __construct(
        public ?int $maxPostLength = null,
        public ?int $maxResponseLength = null,
        public bool $aggregateSuccess = false,
        public int $aggregateSuccessInterval = 60,
        public int $aggregateSuccessMinCount = 100
    ) {
    }
}
