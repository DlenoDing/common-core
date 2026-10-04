<?php
namespace Dleno\CommonCore\Tools\Http {
function microtime($float=false) { ++$GLOBALS['wallCalls']; return $GLOBALS['fakeWall']; }
}
namespace {
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new \RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
$GLOBALS['wallCalls']=0;$GLOBALS['fakeWall']=2000000000;
Swoole\Coroutine\run(function(){
$s=new Swoole\Coroutine\Http\Server('127.0.0.1',0);$s->handle('/',fn($q,$r)=>$r->end((string)$q->server['remote_port']));Swoole\Coroutine::create(fn()=>$s->start());
$o=['min_connections'=>0,'health_path'=>null];$call=fn()=>Pool::request('clock','http://127.0.0.1:'.$s->port.'/','x','POST',[],2,$o);
$a=$call();$GLOBALS['fakeWall']-=3600;$b=$call();
if($a['errCode']||$b['errCode']||$a['body']!==$b['body']||$GLOBALS['wallCalls']!==0)throw new RuntimeException('Wall clock affected pool timing');
echo "PASS pool timing ignores simulated wall-clock rollback and reuses TCP\n";Pool::shutdown();$s->shutdown();
});
}
