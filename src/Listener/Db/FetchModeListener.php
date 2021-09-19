<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Listener\Db;

use Hyperf\Database\Events\StatementPrepared;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use PDO;

/**
 * @Listener
 */
class FetchModeListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            StatementPrepared::class,
        ];
    }

    public function process(object $event)
    {
        if ($event instanceof StatementPrepared) {
            //Db::table('sss')->get()统一返回数组格式
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        }
    }
}
