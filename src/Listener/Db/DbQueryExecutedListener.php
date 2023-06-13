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

use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Str;

/**
 * @Listener
 */
class DbQueryExecutedListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    /**
     * @param QueryExecuted $event
     */
    public function process(object $event)
    {
        if ($event instanceof QueryExecuted) {
            $sql = $event->sql;
            if (strpos($sql, '`__transaction__`') === false) {
                if (!Arr::isAssoc($event->bindings)) {
                    foreach ($event->bindings as $key => $value) {
                        $sql = Str::replaceFirst('?', "'{$value}'", $sql);
                    }
                }
                $sql = str_replace(PHP_EOL, " ", $sql);
                $sql = str_replace("\r", "", $sql);
                $traceId = Server::getTraceId();
                $server = config('app_name') . '(' . Server::getIpAddr() . ')';
                Logger::sqlLog(Logger::SQL_CHANNEL_QUERY)
                      ->debug(
                          sprintf(
                              'Server::%s||Trace-Id::%s||Connection::%s||[%s]||%s',
                              $server,
                              $traceId,
                              $event->connectionName,
                              $event->time,
                              $sql
                          )
                      );
            }
        }
    }
}
