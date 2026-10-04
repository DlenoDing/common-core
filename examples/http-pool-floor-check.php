<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
$l=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($l,false);$pid=pcntl_fork();
if($pid===0){$p=stream_socket_accept($l,5);fclose($l);stream_set_timeout($p,3);for($i=0;$i<2;$i++){$b='';while(!str_contains($b,"\r\n\r\n")){$c=fread($p,4096);if($c==='')exit(2);$b.=$c;}fwrite($p,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");}fclose($p);exit(0);}fclose($l);
Swoole\Coroutine\run(function()use($address){$o=['min_connections'=>2,'health_path'=>null];$call=fn($data)=>Pool::request('floor','http://'.$address.'/',$data,'POST',[],1,$o);$a=$call('one');$b=$call('two');$c=$call('three');echo json_encode(['first'=>$a['errCode'],'failedGrowth'=>$b['errCode'],'nextIndependent'=>$c['errCode'],'live'=>array_values(Pool::statistics())[0]['live']])."\n";if($a['body']!=='ok'||!$b['errCode']||$c['body']!=='ok')throw new RuntimeException('Healthy idle connection was bypassed after growth failed');Pool::shutdown();});pcntl_waitpid($pid,$status);
