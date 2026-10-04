<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Tools\Http;

use Swoole\Coroutine\Http\Client;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;

/** Process-local exclusive HTTP leases. No queue limit and no replay of a business request. */
final class ElasticHttpPool
{
    private static array $pools = [];
    private array $idle = [];
    private array $busy = [];
    private int $creating = 0;
    private int $reconnecting = 0;
    private bool $closed = false;
    private bool $maintaining = false;
    private bool $maintenanceStarted = false;
    private float $retryAt = 0;
    private float $growFloorAt = 0;
    private float $lastBusinessAt = 0;
    private float $lastBusinessGap = 0;
    private int $failures = 0;
    private float $reconnectRound = 0;
    private ?float $failedRound = null;
    private int $created = 0;
    private int $requests = 0;

    private function __construct(private string $host, private int $port, private bool $ssl, private array $options)
    {

    }

    public static function request(string $kind, string $url, $data, string $method, array $headers, $timeout, array $options): array
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP method token');
        }
        $uri = parse_url($url);
        if (!is_array($uri) || empty($uri['host']) || !in_array($uri['scheme'] ?? '', ['http', 'https'], true)
            || isset($uri['user']) || isset($uri['pass'])) throw new \InvalidArgumentException('Invalid pooled HTTP URL');
        $ssl = $uri['scheme'] === 'https';
        $port = $uri['port'] ?? ($ssl ? 443 : 80);
        $path = ($uri['path'] ?? '/') . (isset($uri['query']) ? '?' . $uri['query'] : '');
        $key = $kind . ':' . $uri['scheme'] . '://' . strtolower($uri['host']) . ':' . $port
            . ':' . hash('sha256', serialize($options['transport'] ?? []));
        if (!isset(self::$pools[$key])) {
            self::$pools[$key] = new self($uri['host'], $port, $ssl, $options);
        }
        self::$pools[$key]->startMaintenance();
        return self::$pools[$key]->send($path, $data, strtoupper($method), $headers, $timeout);
    }

    /** Swoole returns false at capacity; Hyperf Engine assigns it to an int and throws TypeError. */
    private static function spawn(callable $callback): bool
    {
        return @\Swoole\Coroutine::create(static function () use ($callback) {
            try { $callback(); }
            catch (\Throwable $error) {
                if (\Hyperf\Context\ApplicationContext::hasContainer()
                    && \Hyperf\Context\ApplicationContext::getContainer()->has(\Hyperf\Contract\StdoutLoggerInterface::class)) {
                    \Hyperf\Context\ApplicationContext::getContainer()->get(\Hyperf\Contract\StdoutLoggerInterface::class)->error((string)$error);
                } else error_log((string)$error);
            }
        }) !== false;
    }

    private function startMaintenance(): void
    {
        if ($this->maintenanceStarted || $this->closed) return;
        $this->maintenanceStarted = true;
        $created = self::spawn(function () {
            try {
                $exit = CoordinatorManager::until(Constants::WORKER_EXIT);
                while (!$this->closed && !$exit->isClosing()) {
                    $this->maintain();
                    if ($exit->yield(1)) break;
                }
            } finally {
                $this->maintenanceStarted = false;
                // Worker/process shutdown closes the pool; a maintenance fault is logged and recoverable.
                if ($this->closed || (isset($exit) && $exit->isClosing())) $this->closeIdle();
            }
        });
        // Coroutine capacity can be exhausted while the current business coroutine still works.
        if (!$created) $this->maintenanceStarted = false;
    }

    /** Test/process shutdown; never close a connection currently executing a business request. */
    public static function shutdown(): void
    {
        foreach (self::$pools as $pool) {
            $pool->closed = true;
            foreach ($pool->idle as $entry) $entry['client']->close();
            $pool->idle = [];
        }
        self::$pools = [];
    }

    public static function statistics(): array
    {
        $out = [];
        foreach (self::$pools as $key => $pool) {
            $live = $pool->liveCount();
            $out[$key] = [
            'live' => $live, 'idle' => count($pool->idle), 'busy' => count($pool->busy),
            'creating' => $pool->creating, 'reconnecting' => $pool->reconnecting, 'maintenanceRunning' => $pool->maintenanceStarted, 'created' => $pool->created, 'requests' => $pool->requests,
            'minimum' => $pool->minimum(), 'minimumSatisfied' => $live >= $pool->minimum(),
            'healthConfigured' => $pool->healthPath() !== null, 'maintenanceFailures' => $pool->failures,
            ];
        }
        return $out;
    }

    private static function now(): float { return \hrtime(true) / 1_000_000_000; }

    private function minimum(): int { return max(0, (int)($this->options['min_connections'] ?? 0)); }

    private function liveCount(?int $stopAt = null): int
    {
        if ($stopAt === 0) return 0;
        $count = 0;
        foreach ($this->idle as $key => $entry) {
            $expired = $this->healthPath() === null
                && self::now() - $entry['released'] > (float)($this->options['max_idle_time'] ?? 60);
            if ($expired || !$this->usable($entry['client'])) {
                $entry['client']->close();
                unset($this->idle[$key]);
                continue;
            }
            ++$count;
            if ($stopAt !== null && $count >= $stopAt) return $count;
        }
        foreach ($this->busy as $client) {
            if ($client->connected) ++$count;
            if ($stopAt !== null && $count >= $stopAt) return $count;
        }
        return $count;
    }

    private function healthPath(): ?string
    {
        $method = strtoupper((string)($this->options['health_method'] ?? 'HEAD'));
        if (!in_array($method, ['GET', 'HEAD'], true)) return null;
        $path = $this->options['health_path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || strpbrk($path, "?#[\\\r\n") !== false) return null;
        return $path;
    }

    private function usable(Client $client): bool
    {
        if (!$client->connected) return false;
        try { $valid = $client->socket->checkLiveness() && $client->socket->peek(1) === false; }
        catch (\Throwable) { $valid = false; }
        // SSL_peek can consume a raw FIN and report differently next time; never revive this lease.
        if (!$valid) $client->close();
        return $valid;
    }

    private function closeIdle(): void
    {
        $this->closed = true;
        foreach ($this->idle as $entry) $entry['client']->close();
        $this->idle = [];
    }

    private function makeClient(): Client
    {
        ++$this->created;
        return new Client($this->host, $this->port, $this->ssl);
    }

    private function send(string $path, $data, string $method, array $headers, $timeout, ?Client $provided = null, bool $maintenance = false, ?float $lastReleased = null): array
    {
        foreach ($headers as $name => &$value) {
            if (is_array($value)) $value = implode(strcasecmp((string)$name, 'Cookie') === 0 ? '; ' : ', ', $value);
            if (strpbrk((string)$name, "\r\n") !== false || strpbrk((string)$value, "\r\n") !== false) {
                throw new \InvalidArgumentException('HTTP headers cannot contain CR/LF');
            }
        }
        unset($value);
        $sparse = false;
        if (!$maintenance) {
            $now = self::now();
            $gap = $this->lastBusinessAt > 0 ? $now - $this->lastBusinessAt : 0;
            $sparse = $this->minimum() > 1
                && max($gap, $this->lastBusinessGap) > (float)($this->options['max_idle_time'] ?? 60) / $this->minimum();
            $this->lastBusinessAt = $now;
            $this->lastBusinessGap = $gap;
        }
        $client = $provided;
        $fresh = false;
        if ($client === null) {
            // Without a safe probe, real requests gradually establish the floor; no synthetic API call.
            $reuse = $this->healthPath() !== null || $sparse || self::now() < $this->growFloorAt
                || count($this->busy) >= $this->minimum();
            if (!$reuse && $this->idle) {
                $reserved = $this->liveCount($this->minimum());
                foreach ($this->busy as $pending) {
                    if (!$pending->connected) ++$reserved;
                    if ($reserved >= $this->minimum()) break;
                }
                $reuse = $reserved >= $this->minimum();
            }
            while ($reuse && $this->idle) {
                // Rotate the external floor so sequential traffic refreshes both connections.
                $entry = $this->healthPath() === null && count($this->idle) <= $this->minimum()
                    ? array_shift($this->idle) : array_pop($this->idle);
                $expired = self::now() - $entry['released'] > (float)($this->options['max_idle_time'] ?? 60)
                    && ($this->healthPath() === null || $this->liveCount() >= $this->minimum());
                if (!$expired && $this->usable($entry['client'])) { $client = $entry['client']; break; }
                $entry['client']->close();
            }
            if ($client === null) { $client = $this->makeClient(); $fresh = true; }
        }
        $id = spl_object_id($client);
        $this->busy[$id] = $client;
        $reusable = false;
        if (!$maintenance) ++$this->requests;
        try {
            $client->set(array_replace([
                'timeout' => $timeout > 0 ? $timeout : -1,
                'connect_timeout' => (float)($this->options['connect_timeout'] ?? 5),
                'keep_alive' => true, 'ssl_verify_peer' => true, 'ssl_allow_self_signed' => false,
                'ssl_host_name' => $this->host,
            ], $this->options['transport'] ?? [], [
                // Per-request deadlines and lease invariants cannot be overridden by transport config.
                'timeout' => $timeout > 0 ? $timeout : -1,
                'connect_timeout' => (float)($this->options['connect_timeout'] ?? 5),
                'keep_alive' => true, 'max_retries' => 0, 'ssl_host_name' => $this->host,
                'defer' => false, 'lowercase_header' => true,
                'read_timeout' => $timeout > 0 ? $timeout : -1,
                'write_timeout' => $timeout > 0 ? $timeout : -1,
            ]));
            $client->cookies = null;
            $client->setMethod($method);
            $client->setData(null);
            $client->setHeaders($headers);
            // Exactly one execute/get/post, including empty bodies and stale connections.
            if ($method === 'GET') $ok = $client->get($path);
            elseif ($method === 'POST') $ok = $client->post($path, $data);
            else { $client->setMethod($method); $client->setData($data); $ok = $client->execute($path); }
            $code = $client->getStatusCode();
            $responseHeaders = $client->getHeaders() ?: [];
            if ($ok === false || $code < 0 || $client->errCode !== 0) return [
                'statusCode' => $code, 'headers' => [], 'body' => '', 'errCode' => $client->errCode ?: -1, 'errMsg' => $client->errMsg,
            ];
            // Swoole 6.1 returns after 1xx and cannot recv the final reply; never lease this stream again.
            if ($code < 200) return [
                'statusCode' => $code, 'headers' => $responseHeaders, 'body' => '',
                'errCode' => -1, 'errMsg' => 'Final HTTP response was not received',
            ];
            $connection = $responseHeaders['connection'] ?? '';
            $connection = is_array($connection) ? implode(',', $connection) : $connection;
            $close = preg_match('/(?:^|,)\s*close\s*(?:,|$)/i', $connection) === 1;
            $reusable = $code > 0 && !$close && $this->usable($client);
            return ['statusCode' => $code, 'headers' => $responseHeaders, 'body' => $client->getBody(), 'errCode' => 0, 'errMsg' => ''];
        } finally {
            unset($this->busy[$id]);
            if (!$maintenance && $fresh && $this->healthPath() === null) {
                $this->growFloorAt = $reusable ? 0 : self::now() + 2;
            }
            if ($reusable && !$this->closed) {
                $this->idle[] = ['client' => $client, 'released' => $lastReleased ?? self::now(), 'checked' => self::now()];
                if (!$maintenance && $this->healthPath() !== null && ($fresh || $this->liveCount($this->minimum()) >= $this->minimum())) {
                    $this->failures = 0; $this->retryAt = 0; $this->failedRound = null;
                    // Successful business recovery supersedes any older probes still completing.
                    $this->reconnectRound = self::now();
                }
            } else $client->close();
        }
    }

    private function maintain(): void
    {
        if ($this->closed || $this->maintaining) return;
        $this->maintaining = true;
        try {
            $now = self::now();
            $live = $this->liveCount();
            foreach ($this->idle as $key => $entry) {
                $usable = $this->usable($entry['client']);
                if (!$usable || (($this->healthPath() === null || $live > $this->minimum())
                    && $now - $entry['released'] > (float)($this->options['max_idle_time'] ?? 60))) {
                    unset($this->idle[$key]);
                    --$live;
                    $entry['client']->close();
                }
            }
            $this->idle = array_values($this->idle);
            $path = $this->healthPath();
            if ($path === null) return;
            // Backoff applies to reconnects; surviving idle connections still need their heartbeats.
            if ($now >= $this->retryAt && $this->reconnecting === 0) {
                $pending = $this->creating;
                foreach ($this->busy as $client) if (!$client->connected) ++$pending;
                $missing = max(0, $this->minimum() - $this->liveCount() - $pending);
                for ($i = 0; $i < $missing; ++$i) {
                    ++$this->creating;
                    ++$this->reconnecting;
                    $created = self::spawn(function () use ($path, $now) {
                        --$this->creating;
                        try {
                            $this->reconnectRound = max($this->reconnectRound, $now);
                            $this->probe($this->makeClient(), $path, null, $now);
                        } finally { --$this->reconnecting; }
                    });
                    if (!$created) { --$this->creating; --$this->reconnecting; break; }
                }
            }
            // Probe only idle sockets. No probe is allowed to borrow a business lease.
            foreach ($this->idle as $key => $entry) {
                if ($now - $entry['checked'] < (float)($this->options['heartbeat'] ?? 20)) continue;
                unset($this->idle[$key]);
                $created = self::spawn(fn () => $this->probe($entry['client'], $path, $entry['released']));
                if (!$created) $this->idle[] = $entry;
                break;
            }
        } finally { $this->maintaining = false; }
    }

    private function probe(Client $client, string $path, ?float $lastReleased = null, ?float $reconnectRound = null): void
    {
        if ($this->closed) { $client->close(); return; }
        $method = strtoupper((string)($this->options['health_method'] ?? 'HEAD'));
        if (!in_array($method, ['GET', 'HEAD'], true)) { $client->close(); return; }
        $response = $this->send($path, '', $method, ['User-Agent' => 'common-core-http-health/1', 'Connection' => 'keep-alive'], 5, $client, true, $lastReleased);
        // Only the current reconnect batch controls backoff; heartbeats and older batches do not.
        if ($reconnectRound === null || $reconnectRound !== $this->reconnectRound) return;
        if ($response['errCode'] || !$client->connected) {
            if ($this->failedRound !== $reconnectRound) {
                ++$this->failures;
                $this->failedRound = $reconnectRound;
            }
            $this->retryAt = self::now() + min(30, 2 ** min($this->failures, 5));
        } else { $this->failures = 0; $this->retryAt = 0; $this->failedRound = null; }
    }
}
