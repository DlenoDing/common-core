<?php

namespace Dleno\CommonCore\Db;


use Dleno\CommonCore\Tools\Arrays\ArrayTool;

/**
 * DataBase数据库配置
 * Class RpcClient
 * @package Dleno\CommonCore\Db
 */
class DataBaseDbConfig
{
    private static $commands = null;
    private static $pool     = null;
    private static $options  = null;
    private static $read     = null;
    private static $write    = null;

    public static function getCommands($poolName, $commands = [])
    {
        if (empty($commands)) {
            $commands = self::getDefaultCommands();
        } else {
            $commands = ArrayTool::merge(self::getDefaultCommands(), $commands);
        }
        return $commands;
    }

    public static function getPool($poolName, $pool = [])
    {
        if (empty($pool)) {
            $pool = self::getDefaultPool();
        } else {
            $pool = ArrayTool::merge(self::getDefaultPool(), $pool);
        }
        return $pool;
    }

    public static function getOptions($poolName, $options = [])
    {
        if (empty($options)) {
            $options = self::getDefaultOptions();
        } else {
            $options = ArrayTool::merge(self::getDefaultOptions(), $options);
        }
        return $options;
    }

    public static function getReadConfig($poolName, $read = [])
    {
        if (empty($read)) {
            $read = self::getDefaultReadConfig();
        } else {
            $read = ArrayTool::merge(self::getDefaultReadConfig(), $read);
        }
        return $read;
    }

    public static function getWriteConfig($poolName, $write = [])
    {
        if (empty($write)) {
            $write = self::getDefaultWriteConfig();
        } else {
            $write = ArrayTool::merge(self::getDefaultWriteConfig(), $write);
        }
        return $write;
    }

    private static function getDefaultCommands()
    {
        self::$commands = [
            'gen:model' => [
                'path'             => 'app/Model',
                'inheritance'      => 'BaseModel',
                'force_casts'      => true,
                'refresh_fillable' => true,
                'with_comments'    => true,
                'uses'             => 'App\Model\BaseModel',
                'property_case'    => \Hyperf\Database\Commands\ModelOption::PROPERTY_SNAKE_CASE,
                'visitors'         => [
                    //数据库中主键
                    \Hyperf\Database\Commands\Ast\ModelRewriteKeyInfoVisitor::class,
                    //软删除
                    \Hyperf\Database\Commands\Ast\ModelRewriteSoftDeletesVisitor::class,
                    //生成对应的 getter 和 setter
                    \Hyperf\Database\Commands\Ast\ModelRewriteGetterSetterVisitor::class,
                ],
            ],
        ];


        return self::$commands;
    }

    private static function getDefaultPool()
    {
        self::$pool = [
            'min_connections' => (int)env('DB_MIN_CONNECTION', 1),
            'max_connections' => (int)env('DB_MAX_CONNECTION', 50),
            'connect_timeout' => (float)env('DB_CONNECT_TIMEOUT', 10.0),
            'wait_timeout'    => (float)env('DB_WAIT_TIMEOUT', 3.0),
            'heartbeat'       => (int)env('DB_HEARTBEAT', -1),
            'max_idle_time'   => (float)env('DB_MAX_IDLE_TIME', 60.0),
        ];

        return self::$pool;
    }

    private static function getDefaultOptions()
    {
        self::$options = [
            /*// 框架默认配置
            \PDO::ATTR_CASE => \PDO::CASE_NATURAL,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_ORACLE_NULLS => \PDO::NULL_NATURAL,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            // 如果使用的为非原生 MySQL 或云厂商提供的 DB 如从库/分析型实例等不支持 MySQL prepare 协议的, 将此项设置为 true
            \PDO::ATTR_EMULATE_PREPARES => false,*/
        ];

        return self::$options;
    }

    private static function getDefaultReadConfig()
    {
        self::$read = [
            'host' => [env('DB_READ_HOST', env('DB_HOST', 'localhost'))],
            'pool' => [
                'min_connections' => (int)env('DB_READ_MIN_CONNECTION', env('DB_MIN_CONNECTION', 1)),
                'max_connections' => (int)env('DB_READ_MAX_CONNECTION', env('DB_MAX_CONNECTION', 50)),
                'connect_timeout' => (float)env('DB_READ_CONNECT_TIMEOUT', env('DB_CONNECT_TIMEOUT', 10.0)),
                'wait_timeout'    => (float)env('DB_READ_WAIT_TIMEOUT', env('DB_WAIT_TIMEOUT', 3.0)),
                'heartbeat'       => (int)env('DB_READ_HEARTBEAT', env('DB_HEARTBEAT', -1)),
                'max_idle_time'   => (float)env('DB_READ_MAX_IDLE_TIME', env('DB_MAX_IDLE_TIME', 60.0)),
            ],
        ];


        return self::$read;
    }

    private static function getDefaultWriteConfig()
    {
        self::$write = [
            'host' => [env('DB_WRITE_HOST', env('DB_HOST', 'localhost'))],
            'pool' => [
                'min_connections' => (int)env('DB_WRITE_MIN_CONNECTION', env('DB_MIN_CONNECTION', 1)),
                'max_connections' => (int)env('DB_WRITE_MAX_CONNECTION', env('DB_MAX_CONNECTION', 50)),
                'connect_timeout' => (float)env('DB_WRITE_CONNECT_TIMEOUT', env('DB_CONNECT_TIMEOUT', 10.0)),
                'wait_timeout'    => (float)env('DB_WRITE_WAIT_TIMEOUT', env('DB_WAIT_TIMEOUT', 3.0)),
                'heartbeat'       => (int)env('DB_WRITE_HEARTBEAT', env('DB_HEARTBEAT', -1)),
                'max_idle_time'   => (float)env('DB_WRITE_MAX_IDLE_TIME', env('DB_MAX_IDLE_TIME', 60.0)),
            ],
        ];


        return self::$write;
    }
}