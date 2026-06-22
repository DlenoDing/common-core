<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Model;

/**
 * 普通模型示例。
 *
 * @property int $id
 * @property string $key
 * @property string $attr1
 * @property int $attr2
 * @property string $attr3
 * @property string $create_time
 * @property string $update_time
 */
class Test extends BaseModel
{
    protected ?string $table = 'test';

    protected array $fillable = ['id', 'key', 'attr1', 'attr2', 'attr3', 'create_time', 'update_time'];

    protected array $casts = ['id' => 'integer', 'attr2' => 'integer', 'attr3' => 'decimal:2'];

    /**
     * 查询示例。只有业务显式调用时才会访问数据库。
     */
    public static function queryExample(string $key): ?self
    {
        return self::query('t')
                   ->where('t.key', $key)
                   ->first();
    }
}
