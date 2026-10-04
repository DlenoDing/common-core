<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php'; require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as P;
Swoole\Coroutine\run(function(){
$s=new Swoole\Coroutine\Http\Server('127.0.0.1',0); $seen=[];
$s->handle('/',function($q,$r) use (&$seen){$path=$q->server['request_uri']; $seen[$path]=($seen[$path]??0)+1;if($path==='/slow'||$path==='/health') Swoole\Coroutine::sleep(.3);$r->end((string)$q->server['remote_port']);}); Swoole\Coroutine::create(fn()=>$s->start());
$url='http://127.0.0.1:'.$s->port; $o=['min_connections'=>0,'health_path'=>null,'max_lifetime'=>.2];$call=fn($kind,$path,$opt)=>P::request($kind,$url.$path,'','POST',[],1,$opt);
$a=$call('expiry','/fast',$o);$b=$call('expiry','/fast',$o);if($a['body']!==$b['body'])throw new Exception('early reuse broken');Swoole\Coroutine::sleep(.23);$stats=P::statistics();foreach($stats as $v)if($v['live']!==0)throw new Exception('Expired idle still counted');$c=$call('expiry','/fast',$o);if($a['body']===$c['body'])throw new Exception('did not retire');echo "PASS reuse before expiry, new TCP after expiry\n";
$slow=$call('busy','/slow',$o);$ref=new ReflectionClass(P::class);foreach($ref->getStaticPropertyValue('pools') as $key=>$pool)if(str_starts_with($key,'busy:') && (new ReflectionProperty($pool,'growFloorAt'))->getValue($pool)!==0.0)throw new Exception('Normal retirement triggered growth backoff');$next=$call('busy','/fast',$o);if($slow['errCode']||$slow['body']===$next['body']||$seen['/slow']!==1)throw new Exception('busy retirement broken');echo "PASS in-flight completion, retire after response, no replay\n";
$off=array_replace($o,['max_lifetime'=>0]);$d=$call('disabled','/fast',$off);Swoole\Coroutine::sleep(.23);$e=$call('disabled','/fast',$off);if($d['body']!==$e['body'])throw new Exception('disable broken');echo "PASS zero disables retirement\n";
$health=['min_connections'=>1,'health_path'=>'/health','health_method'=>'GET','max_lifetime'=>.1];$call('health-age','/fast',$health);Swoole\Coroutine::sleep(1.7);foreach(P::statistics() as $key=>$v)if(str_starts_with($key,'health-age:')){if($v['maintenanceFailures']!==0)throw new Exception('Normal retirement triggered reconnect backoff');echo "PASS successful health retirement does not trigger failure backoff\n";}
P::shutdown();$s->shutdown();
});
