<?php
$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass application vendor/autoload.php');
require dirname(__DIR__).'/src/Tools/Http/ElasticHttpPool.php';require $autoload;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool as Pool;
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);$csr=openssl_csr_new(['commonName'=>'localhost'],$key);openssl_x509_export(openssl_csr_sign($csr,null,$key,1),$cert);$file=tempnam('/tmp','pool-tls');file_put_contents($file,$cert.$private);
Swoole\Coroutine\run(function()use($file){
$s=new Swoole\Coroutine\Http\Server('127.0.0.1',0,true);$s->set(['ssl_cert_file'=>$file,'ssl_key_file'=>$file,'ssl_protocols'=>SWOOLE_SSL_TLSv1_3]);$s->handle('/',fn($q,$r)=>$r->end((string)$q->server['remote_port']));Swoole\Coroutine::create(fn()=>$s->start());
$o=['min_connections'=>0,'health_path'=>null,'transport'=>['ssl_verify_peer'=>false,'ssl_allow_self_signed'=>true,'ssl_protocols'=>SWOOLE_SSL_TLSv1_3]];
$a=Pool::request('tls','https://127.0.0.1:'.$s->port.'/','a','POST',[],2,$o);Swoole\Coroutine::sleep(.1);
$b=Pool::request('tls','https://127.0.0.1:'.$s->port.'/','b','POST',[],2,$o);
if($a['errCode']||$b['errCode']||$a['body']!==$b['body']||array_values(Pool::statistics())[0]['created']!==1)throw new RuntimeException(json_encode([$a,$b,Pool::statistics()]));
echo "PASS TLS 1.3 reuse after idle: one TCP connection\n";Pool::shutdown();$s->shutdown();
$l=new Swoole\Coroutine\Socket(AF_INET,SOCK_STREAM,0);$l->setProtocol(['open_ssl'=>true,'ssl_cert_file'=>$file,'ssl_key_file'=>$file,'ssl_protocols'=>SWOOLE_SSL_TLSv1_3]);$l->bind('127.0.0.1',0);$l->listen();$port=$l->getsockname()['port'];Swoole\Coroutine::create(function()use($l){$p=$l->accept(2);if(!$p||!$p->sslHandshake())throw new RuntimeException('TLS handshake failed');$p->recv(4096,2);$p->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");Swoole\Coroutine::sleep(.04);$p->shutdown(STREAM_SHUT_RDWR);$p->close();$l->close();});$o=['min_connections'=>0,'health_path'=>null,'transport'=>['ssl_verify_peer'=>false,'ssl_allow_self_signed'=>true,'ssl_protocols'=>SWOOLE_SSL_TLSv1_3]];$a=Pool::request('tls-eof','https://localhost:'.$port.'/','a','POST',[],2,$o);if($a['body']!=='ok')throw new RuntimeException(json_encode($a));Swoole\Coroutine::sleep(.08);$first=array_values(Pool::statistics())[0]['live'];$second=array_values(Pool::statistics())[0]['live'];if($first!==0||$second!==0)throw new RuntimeException('TLS EOF revived across repeated checks: '.json_encode([$first,$second]));echo "PASS TLS 1.3 raw FIN stays unusable across repeated checks\n";Pool::shutdown();
});unlink($file);
