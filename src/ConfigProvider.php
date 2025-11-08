<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Dleno\CommonCore;

use Hyperf\JsonRpc\JsonRpcPoolTransporter;
use Hyperf\JsonRpc\JsonRpcTransporter;
use Hyperf\Serializer\Serializer;
use Hyperf\Serializer\SerializerFactory;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Database\Commands\Ast\ModelUpdateVisitor as AstModelUpdateVisitor;
use Hyperf\Database\Model\Builder;
use Dleno\CommonCore\Model\ModelUpdateVisitor;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                //db连接池恒频组件(默认低频组件)[释放连接池中多余的连接]
                //\Hyperf\DbConnection\Frequency::class => \Hyperf\Pool\ConstantFrequency::class,

                //decimal 精度(默认会转float)
                AstModelUpdateVisitor::class => ModelUpdateVisitor::class,

                //JsonRpc连接池-对端jsonrpc时才有用，jsonrpc-http使用的HttpTransporter
                JsonRpcTransporter::class    => JsonRpcPoolTransporter::class,

                //JsonRpc返回 PHP 对象
                NormalizerInterface::class   => new SerializerFactory(Serializer::class),
            ],
            'annotations'  => [
                'scan' => [
                    'paths'              => [
                        __DIR__,
                    ],
                    'class_map'          => [
                        // 需要映射的类名 => 类所在的文件地址
                        Coroutine::class               => __DIR__ . '/class_map/Hyperf/Coroutine/Coroutine.php',
                        Builder::class                 => __DIR__ . '/class_map/Hyperf/Database/Model/Builder.php',
                    ],
                    // ignore_annotations 数组内的注解都会被注解扫描器忽略
                    'ignore_annotations' => [
                        'DateTime',
                        'desc',
                    ],
                ],
            ],
        ];
    }
}
