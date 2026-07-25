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

namespace Dleno\CommonCore\Listener\Db;

use Dleno\CommonCore\Conf\RequestConf;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Context\Context;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Collection\Arr;
use Hyperf\Stringable\Str;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{
    private const DEFAULT_SLOW_SQL_TIME = 1000.0;
    private const ENV_SQL_LOG_MODE = 'COMMON_CORE_SQL_LOG_MODE';
    private const ENV_SLOW_SQL_TIME = 'COMMON_CORE_SLOW_SQL_TIME';

    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    /**
     * @param QueryExecuted $event
     */
    public function process(object $event): void
    {
        if ($event instanceof QueryExecuted) {
            $mode = $this->resolveSqlLogMode();
            if ($mode === RequestConf::LOGGER_SQL_MODE_NONE) {
                return;
            }

            $slowSqlTime = $this->resolveSlowSqlTime();
            $isSlowSql = $event->time > $slowSqlTime;
            if ($mode === RequestConf::LOGGER_SQL_MODE_SLOW_ONLY && !$isSlowSql) {
                return;
            }

            $sql = $event->sql;
            if (strpos($sql, '`__transaction__`') === false) {
                if (!Arr::isAssoc($event->bindings)) {
                    foreach ($event->bindings as $key => $value) {
                        $sql = Str::replaceFirst('?', "'{$value}'", $sql);
                    }
                }
                $sql = str_replace(PHP_EOL, " ", $sql);
                $sql = str_replace("\r", "", $sql);
                $server = config('app_name') . '(' . Server::getIpAddr() . ')';
                //慢查询(默认 >1000ms)以 warning 记录,便于单独告警/排查;普通查询仍 debug
                $level = $isSlowSql ? 'warning' : 'debug';
                Logger::sqlLog(Logger::SQL_CHANNEL_QUERY)
                      ->$level(
                          sprintf(
                              'Server::%s||Connection::%s||[%s]||%s',
                              $server,
                              $event->connectionName,
                              $event->time,
                              $sql
                          )
                      );
            }
        }
    }

    private function resolveSqlLogMode(): int
    {
        $contextMode = $this->normalizeSqlLogMode(Context::get(RequestConf::LOGGER_NO_SQL));
        if ($contextMode !== null) {
            return $contextMode;
        }

        $configMode = $this->normalizeSqlLogMode(config('app.sql_log_mode', env(self::ENV_SQL_LOG_MODE, RequestConf::LOGGER_SQL_MODE_ALL)));
        return $configMode ?? RequestConf::LOGGER_SQL_MODE_ALL;
    }

    private function normalizeSqlLogMode(mixed $mode): ?int
    {
        if ($mode === null || $mode === '') {
            return null;
        }

        if (is_bool($mode)) {
            return $mode ? RequestConf::LOGGER_SQL_MODE_NONE : RequestConf::LOGGER_SQL_MODE_ALL;
        }

        if (is_numeric($mode)) {
            $mode = (int)$mode;
            return in_array($mode, [
                RequestConf::LOGGER_SQL_MODE_ALL,
                RequestConf::LOGGER_SQL_MODE_NONE,
                RequestConf::LOGGER_SQL_MODE_SLOW_ONLY,
            ], true) ? $mode : null;
        }

        switch (strtolower(trim((string)$mode))) {
            case 'all':
            case 'full':
            case 'false':
                return RequestConf::LOGGER_SQL_MODE_ALL;
            case 'none':
            case 'off':
            case 'disable':
            case 'disabled':
            case 'true':
                return RequestConf::LOGGER_SQL_MODE_NONE;
            case 'slow':
            case 'slow_only':
            case 'slow-only':
            case 'slowonly':
                return RequestConf::LOGGER_SQL_MODE_SLOW_ONLY;
            default:
                return null;
        }
    }

    private function resolveSlowSqlTime(): float
    {
        $slowSqlTime = config('app.slow_sql_time', env(self::ENV_SLOW_SQL_TIME, self::DEFAULT_SLOW_SQL_TIME));
        if ($slowSqlTime === null || $slowSqlTime === '' || !is_numeric($slowSqlTime)) {
            return self::DEFAULT_SLOW_SQL_TIME;
        }
        return (float)$slowSqlTime;
    }
}
