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

use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Model\Register;
use Hyperf\DbConnection\Model\Model;
use Hyperf\Utils\Context;
use Hyperf\Utils\Str;

/**
 * Class BaseModel
 *
 * php bin/hyperf.php gen:model {table_name} --path app/Model/{Module}
 *
 * @package Dleno\CommonCore\Model
 */
class BaseModel extends Model
{
    //软删除时间字段
    const DELETED_AT = 'deleted_time';

    /**
     * 对应表名
     * @var string
     */
    protected $table;

    /**
     * 表别名
     * @var string
     */
    protected $alias;

    /**
     * 表别名
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 是否自动加别名
     * @var bool
     */
    public $isAlias = true;

    //不管理时间字段
    public $timestamps = false;

    /**
     * 获取model,替代原来的query()方法，可以同时设置model别名
     * @param null $alias
     * @return ModelBuilder
     */
    public static function getModel($alias = null)
    {
        $query = self::query();
        if (!empty($alias)) {
            $model = $query->getModel();
            $model = $model->setAlias($alias);
            $query = $query->setModel($model);
        }

        return $query;//IDE会提示类型不一致，忽略
    }

    /**
     * Create a new Model query builder for the model.
     *
     * @param \Hyperf\Database\Query\Builder $query
     * @return ModelBuilder
     */
    public function newModelBuilder($query)
    {
        return new ModelBuilder($query);
    }

    /**
     * 设置别名
     * @param $alias
     */
    public function setAlias($alias)
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * 获取别名
     */
    public function getAlias()
    {
        return $this->alias??'';
    }

    /**
     * 静态方法获取表名，主要用于join类的方法，不在代码里直接写表名
     * @param null $alias
     * @return string
     */
    public static function tableName($alias = null)
    {
        return (new static())->getTable($alias);
    }


    /**
     * 获取表名，可重新指定别名
     * @param null $alias
     * @return string
     */
    public function getTable($alias = null)
    {
        $table = $this->table ?? Str::snake(Str::pluralStudly(class_basename($this)));
        if ($this->isAlias) {
            $alias = $alias ?? $this->alias;
            if (!empty($alias)) {
                $table = $table . ' as ' . $alias;
            }
        }
        return $table;
    }

    public function getPrefix()
    {
        $tablePrefix = config('databases.'.$this->getConnectionName().'.prefix');
        return $tablePrefix;
    }

    /**
     * 方法重写，兼容别名功能
     * @param string $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        if (Str::contains($column, '.')) {
            return $column;
        }
        if (empty($this->alias)) {
            $this->alias = $this->table;
        }

        return $this->alias . '.' . $column;
    }

    /**
     * Save the model to the database.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            $this->isAlias = true;
        } else {
            $this->isAlias = false;
        }

        return parent::save($options);
    }

    /**
     * Create a new instance of the given model.
     *
     * @param array $attributes
     * @param bool $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        if ($exists) {
            $this->isAlias = true;
        } else {
            $this->isAlias = false;
        }

        return parent::newInstance($attributes, $exists);
    }
}
