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

#[Listener]
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
    public function process(object $event): void
    {
        if ($event instanceof QueryExecuted) {
            if (Context::get(RequestConf::LOGGER_NO_SQL, false)) {
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
                Logger::sqlLog(Logger::SQL_CHANNEL_QUERY)
                      ->debug(
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
}
