<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
Swoole\Coroutine\run(function(){
$count=0;$s=new Swoole\Coroutine\Http\Server('127.0.0.1',0);
$s->handle('/',function($q,$r)use(&$count){if($q->server['request_uri']==='/health'){++$count;if($count>2)Swoole\Coroutine::sleep(4);$r->header('Connection','close');}$r->end('ok');});
Swoole\Coroutine::create(fn()=>$s->start());
$r=Pool::request('stagger','http://127.0.0.1:'.$s->port.'/','x','POST',[],2,['min_connections'=>0,'health_path'=>null]);
if($r['body']!=='ok')throw new RuntimeException('Business failed');
$pool=array_values((new ReflectionProperty(Pool::class,'pools'))->getValue())[0];
(new ReflectionProperty($pool,'options'))->setValue($pool,['min_connections'=>5,'health_path'=>'/health','health_method'=>'GET']);
Swoole\Coroutine::sleep(4.1);$stats=array_values(Pool::statistics())[0];
if($count!==4||$stats['reconnecting']!==2)throw new RuntimeException('Overlapping reconnect round: '.json_encode([$count,$stats]));
Swoole\Coroutine::sleep(1.1);$stats=array_values(Pool::statistics())[0];
if($stats['reconnecting']!==0||$stats['maintenanceFailures']!==1)throw new RuntimeException('Reconnect accounting did not converge: '.json_encode($stats));
echo "PASS staggered failures stay in one batch and backoff is retained\n";Pool::shutdown();$s->shutdown();
});
