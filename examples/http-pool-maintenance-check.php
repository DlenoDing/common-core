<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
use Hyperf\Coordinator\CoordinatorManager as Manager;
use Hyperf\Coordinator\Constants;
Swoole\Coroutine\run(function(){
$s=new Swoole\Coroutine\Http\Server('127.0.0.1',0);$s->handle('/',fn($q,$r)=>$r->end((string)$q->server['remote_port']));Swoole\Coroutine::create(fn()=>$s->start());
$p=new ReflectionProperty(Manager::class,'container');$p->setValue(null,[Constants::WORKER_EXIT=>new stdClass()]);
$o=['min_connections'=>0,'health_path'=>null];$call=fn()=>Pool::request('fault','http://127.0.0.1:'.$s->port.'/','x','POST',[],2,$o);
$a=$call();$first=array_values(Pool::statistics())[0];if($a['errCode']||$first['maintenanceRunning']||$first['live']!==1)throw new RuntimeException('Maintenance fault discarded usable business connection');
Manager::clear(Constants::WORKER_EXIT);$b=$call();$second=array_values(Pool::statistics())[0];if($b['errCode']||!$second['maintenanceRunning']||$second['created']!==1||$a['body']!==$b['body'])throw new RuntimeException('Maintenance did not recover using existing connection');
echo "PASS maintenance exception is logged; next business restarts maintenance and reuses TCP\n";Pool::shutdown();$s->shutdown();
});
