<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($listener,false);$pid=pcntl_fork();
if($pid===0){for($i=0;$i<2;$i++){$c=stream_socket_accept($listener,5);if(!$c)exit(2);$b='';while(!str_contains($b,"\r\n\r\n"))$b.=fread($c,4096);fwrite($c,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");fclose($c);}fclose($listener);exit(0);}fclose($listener);
(new Hyperf\ExceptionHandler\Listener\ErrorExceptionHandler())->process(new stdClass());
Swoole\Coroutine\run(function()use($address){Swoole\Coroutine::set(['max_coroutine'=>1]);$o=['min_connections'=>0,'health_path'=>null];$a=Pool::request('capacity','http://'.$address.'/','a','POST',[],2,$o);$s=array_values(Pool::statistics())[0];if($a['body']!=='ok'||$s['maintenanceRunning'])throw new RuntimeException('Business request or failed maintenance state incorrect');Swoole\Coroutine::set(['max_coroutine'=>10]);$b=Pool::request('capacity','http://'.$address.'/','b','POST',[],2,$o);if($b['body']!=='ok'||!array_values(Pool::statistics())[0]['maintenanceRunning'])throw new RuntimeException('Maintenance did not recover');echo "PASS coroutine capacity exhaustion preserves business and maintenance recovers\n";Pool::shutdown();});pcntl_waitpid($pid,$status);if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('Fixture failed');
