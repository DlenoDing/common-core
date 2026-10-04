<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
Swoole\Coroutine\run(function(){
$s=new Swoole\Coroutine\Http\Server('127.0.0.1',0);$s->handle('/',function($q,$r){if($q->server['request_uri']==='/slow')Swoole\Coroutine::sleep(.1);$r->end((string)$q->server['remote_port']);});Swoole\Coroutine::create(fn()=>$s->start());$o=['min_connections'=>0,'health_path'=>null];$call=fn($path,$timeout)=>Pool::request('timeouts','http://127.0.0.1:'.$s->port.$path,'x','POST',[],$timeout,$o);
$a=$call('/',.03);$b=$call('/slow',.5);$c=$call('/slow',.02);
if($a['errCode']||$b['errCode']||$a['body']!==$b['body']||!$c['errCode'])throw new RuntimeException('Reused socket retained previous timeout');echo "PASS reused TCP applies longer and shorter request deadlines independently\n";Pool::shutdown();$s->shutdown();
});
