<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Model;

use Dleno\CommonCore\Conf\RpcContextConf;
use Hyperf\Collection\Arr;
use Hyperf\Contract\LengthAwarePaginatorInterface;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Query\Expression;
use Hyperf\Database\Query\Grammars\Grammar;
use Hyperf\DbConnection\Db;
use Hyperf\Paginator\Paginator;
use Hyperf\Stringable\Str;

use function Hyperf\Collection\collect;

/**
 * 项目自定义模型查询构造器。
 *
 * **继承**框架 Builder(享受框架后续修复/安全补丁),在此叠加项目自定义方法与 alias 行为；
 * 由 {@see BaseModel::newModelBuilder()} 注入(Hyperf 的构造器工厂钩子),BaseModel 用 `@mixin` 指向本类 → IDE 可提示这些方法。
 * (替代原 class_map 整类 fork:仅保留增量,不再冻结框架。)
 *
 * @method BaseModel getModel()    复刻原 fork 的 getModel() @return BaseModel|TModel(令自定义方法里 model 专有方法 IDE 可见)
 */
class EloquentBuilder extends Builder
{
    /**
     * 复刻原 fork：把 model 类型标成 BaseModel,使 pager/mypaginate 里 `$this->model->getPerPage()` 等
     * 模型专有方法 IDE 可见、类型正确(运行期不变,仅 docblock)。
     * @var BaseModel
     */
    protected $model;

    /**
     * 复刻原 fork __call：insert / insertGetId 临时关闭表别名(插入不走别名),执行后恢复;
     * 其余转发框架原 __call(parent::__call ≡ 原 __execCall,已逐字核对一致)。
     * @param string $method
     * @param array  $parameters
     */
    public function __call($method, $parameters)
    {
        $insertMethods = [
            'insert',
            'insertGetId',
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
     * 复刻原 fork delete：DELETE 使用无别名表(getTable('')),避免带别名 DELETE 在 MySQL 报错;
     * 其余转发框架原 delete(onDelete 判定 + toBase()->delete())。
     */
    public function delete()
    {
        $table = $this->getModel()
                      ->getTable('');
        if (!empty($table)) {
            $this->from($table);
        }
        return parent::delete();
    }

    /**
     * 把绑定值内插回 SQL 得到"可读完整 SQL"——**仅用于日志/调试展示**。
     * 内插**未做转义**，绝不可把其返回值拿去执行(会造成 SQL 注入)；执行一律用 toSql()+getBindings() 走绑定。
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
     * Db::raw
     * @param string $fields
     * @return Expression
     */
    public function formatRaw(string $fields)
    {
        $tablePrefix = $this->getModel()
                            ->getPrefix();
        $fields      = preg_replace('/([A-Za-z0-9_]+\.)/', $tablePrefix . '$1', $fields);
        return Db::raw($fields);
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
        array_walk(
            $groups,
            function (&$val) {
                if ($val instanceof Expression) {
                    $val = $val->getValue();
                }
            }
        );
        $select     = $queryBuilder->columns;
        $primaryKey = $builder->getModel()
                              ->getKeyName();
        $alias      = $builder->getModel()
                              ->getAlias();
        $countField = [$alias ? $alias . '.' . $primaryKey : $primaryKey];

        if (!empty($select)) {
            foreach ($groups as $grp) {
                foreach ($select as $slt) {
                    if (strpos(
                            strtolower($slt instanceof Expression ? $slt->getValue() : $slt),
                            ' as ' . strtolower($grp)
                        ) !== false) {
                        $countField[] = $slt;
                    }
                }
            }
        }

        //用占位 SQL + 绑定值(原样安全传入),杜绝把绑定值内插回 SQL 裸执行导致的注入
        $builder  = $builder->select($countField);
        $sql      = $builder->toSql();        // 带 ? 占位
        $bindings = $builder->getBindings();  // 对应绑定值

        $count = Db::connection(
            $this->getModel()
                 ->getConnectionName()
        )
                   ->select('select count(*) as aggregate from(' . $sql . ') t where 1', $bindings);
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
    private function mypaginate(
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null
    ): LengthAwarePaginatorInterface {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();
        $builder = $this->toBase();
        //解决group时的count效率问题
        $total   = isset($builder->groups) ? $this->groupCount() : $builder->getCountForPagination();
        $results = $total
            ? $this->forPage($page, $perPage)
                   ->get($columns)
            : $this->model->newCollection();

        return $this->paginator(
            $results,
            $total,
            $perPage,
            $page,
            [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    /**
     * 自定义分页（返回标准的data分页数据）
     * @param array $columns
     * @param int|null $perPage
     * @param int|null $page
     * @return array
     */
    public function pager(array $columns = ['*'], ?int $perPage = null, ?int $page = null)
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
    public function whereFullTextDiy($columns, $text, $mode = null)
    {
        if (is_array($columns)) {
            $columns = join(',', $columns);
        }
        $text    = addslashes($text);
        $modeVal = '';
        if ((is_bool($mode) && $mode) || $mode === 'BOOLEAN') {
            /*
            $text 语法：
                  无符号 默认情况，代表或，自动分词搜索
                  + 必须出现
                  - 必须不出现
                  > 提高该条匹配数据的权重值
                  < 降低该条匹配数据的权重值
                  () 相当于表达式分组，和我们数学中的表达式一个道理
                  ~ 将其相关性由正转负，表示拥有该字会降低相关性
                  * 通配符，只能在字符串后面使用
                  " 完全匹配，被双引号包起来的单词必须整个被匹配
                  @distance 用来测试两个或两个以上的单词是否都在一个指定的距离内
            */
            $modeVal = ' IN BOOLEAN MODE';
        } elseif ($mode === 'LANGUAGE') {
            $modeVal = ' IN NATURAL LANGUAGE MODE';
        } elseif ($mode === 'LANGUAGE_EX') {
            $modeVal = ' IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION';
        } elseif ($mode === 'EXPANSION') {
            $modeVal = ' WITH QUERY EXPANSION';
        } elseif (!is_null($mode)) {
            $modeVal = ' ' . $mode;
        }

        $this->whereRaw("MATCH ($columns) AGAINST ('{$text}'{$modeVal})");
        return $this;
    }

    /**
     * insert or update a record
     *
     * @param array $values
     * @param array $value
     * @return bool
     */
    public function insertOnDuplicate(array $values, array $value)
    {
        $builder = $this->getQuery();   // 查询构造器
        $grammar = $builder->getGrammar();  // 语法器
        // 编译插入语句
        $insert = $grammar->compileInsert($builder, $values);
        // 编译重复后更新列语句。
        $update = $this->compileUpdateColumns($grammar, $value);
        // 构造查询语句
        $query = $insert . ' on duplicate key update ' . $update;
        // 组装sql绑定参数
        $bindings = $this->prepareBindingsForInsertOnDuplicate($grammar, $values, $value);
        // 执行数据库查询
        return $this->getConnection()
                    ->insert($query, $bindings);
    }

    /**
     * Compile all of the columns for an update statement.
     *
     * @param Grammar $grammar
     * @param array $values
     * @return string
     */
    private function compileUpdateColumns(Grammar $grammar, $values)
    {
        return collect($values)
            ->map(
                function ($value, $key) use ($grammar) {
                    return $grammar->wrap($key) . ' = ' . $grammar->parameter($value);
                }
            )
            ->implode(', ');
    }

    /**
     * Prepare the bindings for an insert or update statement.
     *
     * @param Grammar $grammar
     * @param array $values
     * @param array $value
     * @return array
     */
    private function prepareBindingsForInsertOnDuplicate(Grammar $grammar, array $values, array $value)
    {
        // Merge array of bindings
        $bindings = Arr::flatten($values, INF);
        $bindings = array_merge($bindings, array_values($value));
        // Remove all of the expressions from a list of bindings.
        return array_values(
            array_filter(
                $bindings,
                function ($binding) use ($grammar) {
                    return !$grammar->isExpression($binding);
                }
            )
        );
    }
}
