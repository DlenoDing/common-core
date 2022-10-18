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

use Hyperf\Contract\LengthAwarePaginatorInterface;
use Hyperf\Database\Query\Expression;
use Hyperf\Database\Model\Builder;
use Hyperf\DbConnection\Db;
use Hyperf\Paginator\Paginator;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Str;
use Dleno\CommonCore\Conf\RpcContextConf;

/**
 * Class ModelBuilder
 * @package Dleno\CommonCore\Model
 */
class ModelBuilder extends Builder
{
    /**
     * The model being queried.
     *
     * @var BaseModel
     */
    protected $model;

    /**
     * Dynamically handle calls into the query instance.
     *
     * @param string $method
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        $insertMethods = [
            'insert', 'insertGetId',
        ];
        //插入操作不使用别名
        if (in_array($method, $insertMethods)) {
            $model          = $this->getModel();
            $model->isAlias = false;
            $this->setModel($model);
        }
        $object = parent::__call($method, $parameters);
        //执行完成后恢复别名
        if (in_array($method, $insertMethods)) {
            $model          = $this->getModel();
            $model->isAlias = true;
            $this->setModel($model);
        }
        if (!$object instanceof Builder) {
            return $object;
        }
        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setConnection($name)
    {
        $this->model->setConnection($name);

        return $this;
    }

    /**
     * @return string
     */
    public function getConnectionName()
    {
        return $this->model->getConnectionName();
    }

    /**
     * Find a model by its primary key or return fresh model instance.
     *
     * @param array $columns
     * @param mixed $id
     * @return \Hyperf\Database\Model\Model|static
     */
    public function findOrNew($id, $columns = ['*'])
    {
        if (!is_null($model = $this->find($id, $columns))) {
            return $model;
        }

        return $this->newModelInstance();
    }

    /**
     * Get the first record matching the attributes or instantiate it.
     *
     * @return \Hyperf\Database\Model\Model|static
     */
    public function firstOrNew(array $attributes, array $values = [])
    {
        if (!is_null($instance = $this->where($attributes)
                                      ->first())) {
            return $instance;
        }

        return $this->newModelInstance($attributes + $values);
    }

    /**
     * Get the model instance being queried.
     *
     * @return BaseModel
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * 获取完整SQL
     * @return string
     */
    public function getSql()
    {
        $sql      = $this->toSql();
        $bindings = $this->getBindings();
        if (!Arr::isAssoc($bindings)) {
            foreach ($bindings as $key => $value) {
                $sql = Str::replaceFirst('?', "'{$value}'", $sql);
            }
        }
        return $sql;
    }

    /**
     * 转换为DB RAW数据
     * @param string $fields
     * @return Expression
     */
    public function formatRaw(string $fields)
    {
        $tablePrefix = $this->getModel()
                            ->getPrefix();
        $fields      = preg_replace('/([A-Za-z0-9_]+\.)/', $tablePrefix . '$1', $fields);
        return \Hyperf\DbConnection\Db::raw($fields);
    }

    /**
     * 带group的查询统计
     * @return int
     */
    public function groupCount()
    {
        $builder = clone $this;

        $queryBuilder = $builder->toBase();
        $groups       = $queryBuilder->groups;
        array_walk($groups, function (&$val) {
            if ($val instanceof Expression) {
                $val = $val->getValue();
            }
        });
        $select     = $queryBuilder->columns;
        $primaryKey = $builder->getModel()
                              ->getKeyName();
        $alias      = $builder->getModel()
                              ->getAlias();
        $countField = [$alias ? $alias . '.' . $primaryKey : $primaryKey];

        if (!empty($select)) {
            foreach ($groups as $grp) {
                foreach ($select as $slt) {
                    if (strpos(strtolower($slt instanceof Expression ? $slt->getValue() : $slt), ' as ' . strtolower($grp)) !== false) {
                        $countField[] = $slt;
                    }
                }
            }
        }

        $sql = $builder->select($countField)
                       ->getSql();

        $count = Db::connection($this->getModel()
                                     ->getConnectionName())
                   ->select('select count(*) as aggregate from(' . $sql . ') t where 1');
        $count = $count[0]['aggregate'] ?? 0;

        return $count;
    }

    /**
     * 重写 paginate
     * @param int|null $perPage
     * @param array $columns
     * @param string $pageName
     * @param int|null $page
     * @return LengthAwarePaginatorInterface
     */
    public function mypaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginatorInterface
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();
        $builder = $this->toBase();
        //解决group时的count效率问题
        $total   = isset($builder->groups) ? $this->groupCount() : $builder->getCountForPagination();
        $results = $total
            ? $this->forPage($page, $perPage)
                   ->get($columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * 自定义分页（返回标准的data分页数据）
     * @param array $columns
     * @param int|null $perPage
     * @param int|null $page
     * @return array
     */
    public function pager(array $columns = ['*'], int $perPage = null, int $page = null)
    {
        $perPage = $perPage ?? rpc_context_get(RpcContextConf::PER_PAGE);
        $perPage = intval($perPage ?? $this->model->getPerPage());
        $this->model->setPerPage($perPage);
        $page = $page ?? rpc_context_get(RpcContextConf::PAGE);
        $page = intval($page ?? 1);

        $paginate = $this->mypaginate($perPage, $columns, 'page', $page);
        $pager    = [
            "perPage"   => $perPage,//每页记录大小
            "currPage"  => $paginate->currentPage(),//当前页
            "itemTotal" => $paginate->total(),//总记录数
            "pageTotal" => $paginate->lastPage(),//总页数
        ];
        $list     = [];
        foreach ($paginate->items() as $item) {
            $list[] = $item->toArray();
        }
        return [
            'list'  => $list,
            'pager' => $pager,
        ];
    }

    /**
     * @param $columns
     * @param $text
     * @param null $mode
     * @return $this
     */
    public function whereFullText($columns, $text, $boolMode = false)
    {
        if (is_array($columns)) {
            $columns = join(',', $columns);
        }
        $text = addslashes($text);
        $boolMode = $boolMode?' IN BOOLEAN MODE':'';
        $this->whereRaw("MATCH ($columns) AGAINST ('{$text}'{$boolMode})");
        return $this;
    }

    /**
     * Delete a record from the database.
     */
    public function delete()
    {
        $table = $this->getModel()->getTable('');
        if (!empty($table)) {
            $this->from($table);
        }

        return parent::delete();
    }
}
