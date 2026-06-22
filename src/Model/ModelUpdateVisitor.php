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
namespace Dleno\CommonCore\Model;

use Hyperf\Database\Commands\Ast\ModelUpdateVisitor as Visitor;
use Hyperf\Database\Commands\ModelOption;
use Hyperf\Stringable\Str;

class ModelUpdateVisitor extends Visitor
{
    /**
     * gen:model 时,decimal 列改用「列的真实小数位」作 cast 精度,
     * 避免父类对所有 decimal 写死 `decimal:2` —— 那样 `decimal(20,8)`(金额/汇率) 生成的 cast 只留 2 位、
     * 运行时高精度值被截。
     * 做法:父类 rewriteCasts 循环里「$column['cast'] 已存在则直接采用、跳过 formatDatabaseType」,
     * 故此处在构造时按 column_type(如 `decimal(20,8)`)解析真实 scale 并预置 cast;
     * 已显式带 cast 的列(--force-casts/既有)不覆盖;拿不到 column_type 时回退 2。
     * @param array $columns
     */
    public function __construct(string $class, array $columns, ModelOption $option)
    {
        foreach ($columns as $i => $column) {
            if (empty($column['cast']) && ($column['data_type'] ?? '') === 'decimal') {
                $scale = 2;
                //column_type 形如 decimal(20,8) / decimal(10,4)；第二个数=小数位
                if (preg_match('/^decimal\(\s*\d+\s*,\s*(\d+)\s*\)/i', (string) ($column['column_type'] ?? ''), $m)) {
                    $scale = (int) $m[1];
                }
                $columns[$i]['cast'] = 'decimal:' . $scale;
            }
        }
        parent::__construct($class, $columns, $option);
    }

    protected function formatDatabaseType(string $type): ?string
    {
        switch ($type) {
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                return 'integer';
            case 'decimal':
                //兜底:正常已由构造按真实 scale 预置 cast;此处仅在拿不到 column_type 时回退 :2
                return 'decimal:2';
            case 'float':
            case 'double':
            case 'real':
                return 'float';
            case 'bool':
            case 'boolean':
                return 'boolean';
            default:
                return null;
        }
    }

    protected function formatPropertyType(string $type, ?string $cast): ?string
    {
        if (! isset($cast)) {
            $cast = $this->formatDatabaseType($type) ?? 'string';
        }

        //PHP 原生枚举 cast(如 casts=['status'=>StatusEnum::class])→ @property 用 FQCN(带前导 \),与 Hyperf 原版一致;
        //本覆盖版 fork 时漏了此分支,会让枚举 cast 列的 docblock 类型变成裸类名(仅影响 IDE 提示)。
        if (enum_exists($cast)) {
            return '\\' . $cast;
        }

        switch ($cast) {
            case 'integer':
                return 'int';
            case 'date':
            case 'datetime':
                return $this->uses['Carbon\Carbon'] ?? '\Carbon\Carbon';
            case 'json':
                return 'array';
        }

        if (Str::startsWith($cast, 'decimal')) {
            // 如果 cast 为 decimal，则 @property 改为 string
            return 'string';
        }

        return $cast;
    }
}
