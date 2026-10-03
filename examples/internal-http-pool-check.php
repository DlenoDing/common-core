<?php

// Pure loopback check; use an application's installed Hyperf/Swoole dependencies.
$autoload = getenv('COMMON_CORE_TEST_AUTOLOAD') ?: ($argv[1] ?? '');
if ($autoload === '' || !is_file($autoload)) throw new \RuntimeException('Pass the application vendor/autoload.php path');
if (!class_exists(\Dleno\CommonCore\Tools\Http\HttpClient::class, false)) require dirname(__DIR__) . '/src/Tools/Http/HttpClient.php';
require $autoload;
$container = new \Hyperf\Di\Container(new \Hyperf\Di\Definition\DefinitionSource([]));
$container->set(\Hyperf\Contract\ConfigInterface::class, new \Hyperf\Config\Config(['http_client' => ['internal_pool' => ['max_connections' => 8]]]));
\Hyperf\Context\ApplicationContext::setContainer($container);

\Swoole\Coroutine\run(function () {
    $listener = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
    $listener->bind('127.0.0.1', 0);
    $listener->listen();
    $port = $listener->getsockname()['port'];
    $count = 0;
    $requests = [];
    $sockets = [];
    $stop = false;
    $check = static function ($condition, $message) { if (!$condition) throw new \RuntimeException($message); };
    \Swoole\Coroutine::create(function () use ($listener, &$count, &$requests, &$sockets, &$stop) {
        while (!$stop) {
            $socket = $listener->accept(0.1);
            if (!$socket) continue;
            $id = ++$count;
            $sockets[] = $socket;
            \Swoole\Coroutine::create(function () use ($socket, $id, &$requests) {
                $buffer = '';
                while (true) {
                    while (!str_contains($buffer, "\r\n\r\n")) {
                        $chunk = $socket->recv(2);
                        if ($chunk === false || $chunk === '') return;
                        $buffer .= $chunk;
                    }
                    [$header, $buffer] = explode("\r\n\r\n", $buffer, 2);
                    $lines = explode("\r\n", $header);
                    $requestLine = array_shift($lines);
                    $headers = [];
                    foreach ($lines as $line) { [$name, $value] = explode(':', $line, 2); $headers[strtolower($name)] = trim($value); }
                    $length = (int)($headers['content-length'] ?? 0);
                    while (strlen($buffer) < $length) {
                        $chunk = $socket->recv(2);
                        if ($chunk === false || $chunk === '') return;
                        $buffer .= $chunk;
                    }
                    $body = substr($buffer, 0, $length);
                    $buffer = substr($buffer, $length);
                    $requests[] = ['line' => $requestLine, 'body' => $body, 'headers' => $headers];
                    if (str_contains($requestLine, '/slow')) \Swoole\Coroutine::sleep(0.2);
                    if (str_contains($requestLine, '/drop')) { $socket->close(); return; }
                    $payload = json_encode(['connection' => $id, 'body' => $body, 'owner' => $headers['x-owner'] ?? '', 'cookie' => $headers['cookie'] ?? '']);
                    $status = str_contains($requestLine, '/error') ? '500 Error' : (str_contains($requestLine, '/redirect') ? '302 Found' : '200 OK');
                    $socket->sendAll("HTTP/1.1 $status\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload)
                        . "\r\nConnection: keep-alive\r\nSet-Cookie: must-not-persist=1\r\nLocation: /must-not-follow\r\n\r\n" . $payload);
                }
            });
        }
    });
    $post = static fn ($path, $body, $owner, $timeout = 2) => \Dleno\CommonCore\Tools\Http\HttpClient::postInternal(
        "http://127.0.0.1:$port$path", $body, ['X-Owner' => $owner], $timeout
    );
    try {
        $one = $post('/one', 'ciphertext-one', 'one');
        $two = $post('/two', 'ciphertext-two', 'two');
        $a = json_decode($one['body'], true);
        $b = json_decode($two['body'], true);
        $check($one['statusCode'] === 200 && $two['statusCode'] === 200, 'Responses failed');
        $check($a['connection'] === $b['connection'], 'TCP connection not reused');
        $check($b['owner'] === 'two' && $b['body'] === 'ciphertext-two' && $b['cookie'] === '', 'Headers/body/cookies leaked');
        $check($post('/error', 'error', 'error')['statusCode'] === 500, 'HTTP errors must be returned');
        $check($post('/redirect', 'redirect', 'redirect')['statusCode'] === 302, 'Redirect must not be followed');
        $failed = $post('/slow', 'timeout', 'timeout', 0.05);
        $check($failed['errCode'] !== 0, 'Timeout not reported');
        \Swoole\Coroutine::sleep(0.25);
        $check(count(array_filter($requests, static fn ($r) => str_contains($r['line'], '/slow'))) === 1, 'Timed-out POST repeated');
        $drop = $post('/drop', 'drop', 'drop');
        $check($drop['errCode'] !== 0, 'Reset not reported');
        $check(count(array_filter($requests, static fn ($r) => str_contains($r['line'], '/drop'))) === 1, 'Reset POST repeated');
        $check($post('/after-reset', 'after', 'after')['statusCode'] === 200, 'Failed connection not replaced');
        $done = new \Swoole\Coroutine\Channel(16);
        for ($i = 0; $i < 16; $i++) \Swoole\Coroutine::create(function () use ($i, $post, $done) {
            $result = $post('/parallel', 'cipher-' . $i, 'owner-' . $i);
            $done->push([$i, $result]);
        });
        for ($i = 0; $i < 16; $i++) {
            [$n, $response] = $done->pop(3);
            $data = json_decode($response['body'], true);
            $check($data['owner'] === 'owner-' . $n && $data['body'] === 'cipher-' . $n && $data['cookie'] === '', 'Concurrent request leaked');
        }
        $check(!array_filter($requests, static fn ($r) => str_contains($r['line'], '/must-not-follow')), 'Redirect generated an extra request');
        echo "PASS internal HTTP keep-alive, concurrent isolation, no cookies/redirects/retry, timeout/reset recovery\n";
        echo 'Accepted connections: ' . $count . '; requests: ' . count($requests) . "\n";
    } finally {
        $stop = true;
        foreach ($sockets as $socket) { if ($socket->fd >= 0) $socket->close(); }
        $listener->close();
    }
});
