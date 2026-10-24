<?php
// Isolated HTTP replacement. Records actual requests independently of the job.
$server = new Swoole\Http\Server('0.0.0.0', 8080, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'log_file' => '/tmp/http.log']);
$server->on('request', function ($request, $response) {
    file_put_contents('/out/http.jsonl', json_encode(['path' => $request->server['request_uri'], 'time' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
    if ($request->server['request_uri'] === '/slow') Swoole\Coroutine::sleep((int)($request->get['seconds'] ?? 0));
    $response->end('ok');
});
$server->start();
