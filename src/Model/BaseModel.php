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

use Dleno\CommonCore\Tools\Logger;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use Hyperf\DbConnection\Model\Model;
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
    //分表模式：0不分表1按年2按月3按日4按周5固定数量  [分表]
    const SPLIT_MODE_NO    = 0;
    const SPLIT_MODE_YEAR  = 1;
    const SPLIT_MODE_MONTH = 2;
    const SPLIT_MODE_DAY   = 3;
    const SPLIT_MODE_WEEK  = 4;
    const SPLIT_MODE_NUM   = 5;

    /*
     一:数量分表（需要主表控制ID）
        1.主表生成ID
        2.查询（单条）：根据ID计算表名
        3.查询（列表）：查主表自增值，计算出当前最新表 static本地缓存
        4.添加：插主表获取ID，删主表；插ID对应表（底层不好修改）
        5.表是否存在
            是-》直接插入
            否-》1.加锁；2.建表（新表自增值为主表值）更新static本地缓存
     二:日期分表（建表时关系表记录初始自增和表名）
        1.ID生成不用管
        2.查询（单条）：根据ID找关系表，再查询（涉及到where In的就不好查了）
        3.查询（列表）：根据当前时间计算 static本地缓存
        4.添加：直接插入
        5.表是否存在
            是-》直接插入
            否-》1.加锁；2.建表（新表自增值为上一个表值）更新static本地缓存
     */

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
     * 表主键
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 对应表名(基础表名)[分表]
     * @var string
     */
    protected $baseTable;

    /**
     * 分表模式[分表]
     * @var string
     */
    protected $splitMode = self::SPLIT_MODE_NO;

    /**
     * SPLIT_MODE_NUM时的单表最大记录数(设置后不能再修改)[分表]
     * @var string
     */
    protected $splitMaxNum = 3000000;

    /**
     * 是否自动加别名
     * @var bool
     */
    public $isAlias = true;

    //不管理时间字段
    public $timestamps = false;

    /*
     * 当前表名（建新表时更新）[分表]
     * @var array
     */
    protected static $currTable = [];

    /**
     * Create a new Model model instance.
     */
    public function __construct(array $attributes = [], $connection = null)
    {
        $connection && $this->setConnection($connection);

        if (empty($this->baseTable)) {
            $this->baseTable = $this->table ?? Str::snake(Str::pluralStudly(class_basename($this)));
        }
        if ($this->splitMode != self::SPLIT_MODE_NO) {
            $primaryId = $attributes[$this->primaryKey] ?? null;
            $this->splitInitTable($primaryId);
        }
        parent::__construct($attributes);
    }

    private function splitInitTable($primaryId = null)
    {
        $tableName = $this->splitGetCurrTableName($primaryId);
        $this->setTable($tableName);
        $this->splitCheckInitTable($tableName, $primaryId);
    }

    private function splitGetCurrTableName($primaryId = null)
    {
        if (!empty($primaryId)) {
            if (in_array(
                $this->splitMode,
                [
                    self::SPLIT_MODE_YEAR,
                    self::SPLIT_MODE_MONTH,
                    self::SPLIT_MODE_DAY,
                    self::SPLIT_MODE_WEEK,
                ]
            )) {
                $tableName = $this->splitGetTableByPrimaryIdModeTime($primaryId);
                if (empty($tableName)) {
                    $tableName = $this->splitGetCurrTableByTime();
                }
            } elseif ($this->splitMode == self::SPLIT_MODE_NUM) {
                $tableName = $this->splitGetTableByPrimaryIdModeNum($primaryId);
            } else {
                $tableName = $this->baseTable;
            }
        } else {
            if (in_array(
                $this->splitMode,
                [
                    self::SPLIT_MODE_YEAR,
                    self::SPLIT_MODE_MONTH,
                    self::SPLIT_MODE_DAY,
                    self::SPLIT_MODE_WEEK,
                ]
            )) {
                $tableName = $this->splitGetCurrTableByTime();
            } elseif ($this->splitMode == self::SPLIT_MODE_NUM) {
                $tableName = $this->splitGetCurrTableByNum();
            } else {
                $tableName = $this->baseTable;
            }
        }

        return $tableName;
    }

    private function splitCheckInitTable($tableName, $primaryId = null)
    {
        $connectionName = $this->getConnectionName();
        if ((static::$currTable[$connectionName][$this->baseTable] ?? '') == $tableName) {
            return true;
        }
        if (!Schema::hasTable($tableName)) {
            try {
                $autoIncrement = 1;
                if (in_array(
                    $this->splitMode,
                    [
                        self::SPLIT_MODE_YEAR,
                        self::SPLIT_MODE_MONTH,
                        self::SPLIT_MODE_DAY,
                        self::SPLIT_MODE_WEEK,
                    ]
                )) {
                    $prevTable     = $this->splitGetPrevTableData();
                    $autoIncrement = $prevTable['AUTO_INCREMENT'] ?? 1;
                } elseif ($this->splitMode == self::SPLIT_MODE_NUM) {
                    $num = explode('@', $tableName);
                    $num = intval($num[1] ?? 0);

                    $autoIncrement = $this->splitMaxNum * $num + 1;
                }
                //show create table `table_name`;
                $prefix = $this->getPrefix();
                Db::connection($connectionName)
                  ->update('CREATE TABLE `' . $prefix . $tableName . '` LIKE `' . $prefix . $this->baseTable . '`');
                if ($autoIncrement > 1) {
                    Db::connection($connectionName)
                      ->update('ALTER TABLE `' . $prefix . $tableName . '` AUTO_INCREMENT=' . $autoIncrement);
                }
            } catch (\Throwable $e) {
                Logger::systemLog('DB-SPLIT-ERROR')
                      ->info($e->getMessage());
            }
        }

        if (empty($primaryId)) {
            self::$currTable[$connectionName][$this->baseTable] = $tableName;
        }
        return true;
    }

    private function splitGetCurrTableByNum()
    {
        $connectionName = $this->getConnectionName();
        $tableName = $this->baseTable . '@';
        if (!empty(static::$currTable[$connectionName][$this->baseTable] ?? '')) {
            $tableName = self::$currTable[$connectionName][$this->baseTable];
        } else {
            $mainTable = $this->splitGetMainTableData();
            $tableName .= sprintf("%05d", ceil(($mainTable['AUTO_INCREMENT'] ?? 1) / $this->splitMaxNum));
        }
        return $tableName;
    }

    private function splitGetCurrTableByTime()
    {
        $tableName = $this->baseTable . '@';
        if ($this->splitMode == self::SPLIT_MODE_YEAR) {
            $tableName .= date('Y');
        } elseif ($this->splitMode == self::SPLIT_MODE_MONTH) {
            $tableName .= date('Y-m');
        } elseif ($this->splitMode == self::SPLIT_MODE_DAY) {
            $tableName .= date('Y-m-d');
        } elseif ($this->splitMode == self::SPLIT_MODE_WEEK) {
            $tableName .= date('Y-W');
        } else {
            $tableName = $this->baseTable;
        }
        return $tableName;
    }

    private function splitGetTableByPrimaryIdModeNum($primaryId)
    {
        $tableName = $this->baseTable . '@';
        $tableName .= sprintf("%05d", ceil($primaryId / $this->splitMaxNum));
        return $tableName;
    }

    private function splitGetTableByPrimaryIdModeTime($primaryId)
    {
        $database  = $this->getDataBase();
        $prefix    = $this->getPrefix();
        $tableName = Db::connection($this->getConnectionName())
                       ->select(
                           "SELECT `TABLE_NAME` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`='{$database}' and `TABLE_NAME` LIKE '{$prefix}{$this->baseTable}@%' and AUTO_INCREMENT>'{$primaryId}' ORDER BY `AUTO_INCREMENT` ASC LIMIT 1"
                       );
        $tableName = $tableName['TABLE_NAME'] ?? null;
        return $tableName;
    }

    private function splitGetPrevTableData()
    {
        $database  = $this->getDataBase();
        $prefix    = $this->getPrefix();
        $prevTable = Db::connection($this->getConnectionName())
                       ->select(
                           "SELECT `TABLE_NAME`,`AUTO_INCREMENT` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`='{$database}' and `TABLE_NAME` LIKE '{$prefix}{$this->baseTable}@%' ORDER BY `TABLE_NAME` DESC LIMIT 1"
                       );
        $prevTable = $prevTable[0] ?? [];
        if (empty($prevTable)) {
            $prevTable = $this->splitGetMainTableData();
        }
        return $prevTable;
    }

    private function splitGetMainTableData()
    {
        $database  = $this->getDataBase();
        $prefix    = $this->getPrefix();
        $prevTable = Db::connection($this->getConnectionName())
                       ->select(
                           "SELECT `TABLE_NAME`,`AUTO_INCREMENT` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`='{$database}' and `TABLE_NAME` = '{$prefix}{$this->baseTable}' ORDER BY `TABLE_NAME` DESC LIMIT 1"
                       );
        $prevTable = $prevTable[0] ?? [];
        return $prevTable;
    }

    public function splitSetTableByPrimaryId($primaryId)
    {
        if ($this->splitMode != self::SPLIT_MODE_NO) {
            if (in_array(
                $this->splitMode,
                [
                    self::SPLIT_MODE_YEAR,
                    self::SPLIT_MODE_MONTH,
                    self::SPLIT_MODE_DAY,
                    self::SPLIT_MODE_WEEK,
                ]
            )) {
                $tableName = $this->splitGetTableByPrimaryIdModeTime($primaryId);
            } elseif ($this->splitMode == self::SPLIT_MODE_NUM) {
                $tableName = $this->splitGetTableByPrimaryIdModeNum($primaryId);
            }
            if (!empty($tableName)) {
                $this->setTable($tableName);
            }
        }
        return $this;
    }

    /**
     * 老方法
     * @param null $alias 指定别名，不指定则使用属性定义
     * @param null $connection 数据连接名，不指定则使用属性定义
     * @param null $primaryId 分表时使用，用于根据$primaryId定位所属的表
     * @return \Hyperf\Database\Model\Builder
     */
    public static function getModel($alias = null, $connection = null, $primaryId = null)
    {
        return static::query($alias, $connection, $primaryId);
    }

    /**
     * Begin querying the model.
     * @param null|string $alias 指定别名，不指定则使用属性定义
     * @param null|string $connection 数据连接名，不指定则使用属性定义
     * @param null|int $primaryId 分表时使用，用于根据$primaryId定位所属的表
     * @return \Hyperf\Database\Model\Builder
     */
    public static function query($alias = null, $connection = null, $primaryId = null)
    {
        $instance = new static([], $connection);
        if (!empty($alias)) {
            $instance->setAlias($alias);
        }
        if (!empty($primaryId)) {
            $instance->splitSetTableByPrimaryId($primaryId);
        }
        //IDE会提示类型不一致，忽略
        return $instance->newQuery();
    }

    /**
     * Begin querying the model on a given connection.
     *
     * @param null|string $connection 数据连接名，不指定则使用属性定义
     * @param null|string $alias 指定别名，不指定则使用属性定义
     * @param null|int $primaryId 分表时使用，用于根据$primaryId定位所属的表
     * @return \Hyperf\Database\Model\Builder
     */
    public static function on($connection = null, $alias = null, $primaryId = null)
    {
        return static::query($alias, $connection, $primaryId);
    }

    /**
     * Begin querying the model on the write connection.
     *
     * @return \Hyperf\Database\Query\Builder
     */
    public static function onWriteConnection($connection = null, $alias = null)
    {
        return static::query($alias, $connection)
                     ->useWritePdo();
    }

    /**
     * Get all of the models from the database.
     *
     * @param array|mixed $columns
     * @return \Hyperf\Database\Model\Collection|static[]
     */
    public static function all($columns = ['*'], $connection = null)
    {
        return static::query(null, $connection)
                     ->get(is_array($columns) ? $columns : func_get_args());
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @param array|string $relations
     * @return \Hyperf\Database\Model\Builder|static
     */
    public static function with($relations, $connection = null)
    {
        return static::query(null, $connection)
                     ->with(is_string($relations) ? func_get_args() : $relations);
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
        return $this->alias ?? '';
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
        $tablePrefix = config('databases.' . $this->getConnectionName() . '.prefix');
        return $tablePrefix;
    }

    public function getDataBase()
    {
        $database = config('databases.' . $this->getConnectionName() . '.database');
        return $database;
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
