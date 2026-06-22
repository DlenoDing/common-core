<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Model;

/**
 * 分表模型示例。
 *
 * 按月分表:逻辑主表 test_split,物理分表形如 test_split@2026-06。
 * 只有业务显式查询/写入时才会访问数据库或建表。
 */
class TestSplit extends BaseModel
{
    protected $splitMode = self::SPLIT_MODE_MONTH;

    protected string $primaryKey = 'id';

    protected ?string $table = 'test_split';

    protected array $fillable = ['id', 'key', 'attr1', 'attr2', 'attr3', 'create_time', 'update_time'];

    protected array $casts = ['id' => 'integer', 'attr1' => 'integer'];

    /**
     * 按主键路由到所属分表查询。
     */
    public static function findExample(int|string $id): ?self
    {
        return self::findById($id);
    }

    /**
     * 指定分表后缀查询,例如 withTable('2026-06')。
     */
    public static function withTableExample(string $suffix): array
    {
        return self::withTable($suffix)
                   ->limit(10)
                   ->get()
                   ->toArray();
    }
}
