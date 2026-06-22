<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Websocket\Listener;

use Dleno\CommonCore\Websocket\Support\WsProcessSwitch;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Redis\Redis;
use Swoole\Coroutine;

/**
 * 启动前置校验（启用 WebSocket 服务时）：① 运行模式必须 SWOOLE_BASE；② Redis 必须支持 HEXPIRE(Redis 7.4+)。
 * 运行模式不满足、或 Redis 可达但不支持 HEXPIRE → 打印提示并终止整个启动(fail-fast,避免运行期才出错);
 * Redis 暂时不可达 → 仅 WARN 放行,避免启动期误杀临时网络/依赖编排抖动。
 *
 * ① server.mode = SWOOLE_BASE（根因在框架层）：
 *  Hyperf WebSocket 的 per-fd 状态 —— FdCollector::$fds(决定 onMessage 是否丢弃) 与
 *  Hyperf\WebSocketServer\Context::$container(存握手 Request 等按 fd 数据) —— 均为 worker 进程私有 static。
 *  握手在接受连接的 worker 写入、消息在收到帧的 worker 读取,故要求同一连接的握手/消息/关闭落在同一 worker。
 *  - SWOOLE_BASE：每 worker 自身为 reactor、端到端独占自己 accept 的连接 → 始终同 worker,安全。
 *  - SWOOLE_PROCESS：master 按 dispatch 分发,握手与消息可能落到不同 worker → fd 数据取不到、消息被丢弃。
 *
 * ② Redis 7.4+(HEXPIRE)：presence 在线索引与连接绑定反向索引都用 HEXPIRE 给 hash field 设独立 TTL(死连接自洁)。
 *  < 7.4 无该命令,运行期会导致绑定/在线判断出错。用能力探测而非版本号字符串——托管/分支版(KeyDB/Dragonfly/云)版本号不可靠。
 *
 * 选用 BeforeMainServerStart 事件：仅在“真正启动服务”时触发、且仅一次、在主进程内、监听之前,
 * 因此 fail-fast 不会误伤 migrate 等其它命令;此时 exit 可干净中止启动。
 */
#[Listener]
class WsServerModeCheckListener implements ListenerInterface
{
    public function __construct(private ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        // 仅启用了 WebSocket 服务时才校验
        if (! WsProcessSwitch::hasWebSocketServer()) {
            return;
        }
        $this->checkServerMode();    // 模式不满足会直接 exit(1)
        $this->checkRedisHExpire();
    }

    /** server.mode 必须 SWOOLE_BASE,否则终止。 */
    private function checkServerMode(): void
    {
        if ($this->config->get('server.mode') === SWOOLE_BASE) {
            return;
        }
        $this->fatal([
            '检测到启用了 WebSocket 服务，但 server.mode 不是 SWOOLE_BASE。',
            '',
            'Hyperf WebSocket 的 per-fd 状态（FdCollector / WsContext）为 worker 进程私有，',
            '必须 SWOOLE_BASE 才能保证同一连接的握手/消息落在同一 worker；',
            'SWOOLE_PROCESS 模式下握手与消息可能分到不同 worker，导致客户端 FD 数据丢失、消息被丢弃。',
            '',
            '请将 config/autoload/server.php 的 mode 改为 SWOOLE_BASE 后重新启动。',
        ]);
    }

    /**
     * 探测 Redis 是否支持 HEXPIRE(Redis 7.4+)。
     * 能连 Redis 但 HEXPIRE 不可用(< 7.4 或不支持的分支)→ 终止;连不上 Redis → 仅告警放行(避免启动期误杀临时网络/依赖编排抖动)。
     */
    private function checkRedisHExpire(): void
    {
        //连接池仅协程内可取连接;BeforeMainServerStart 在主进程(非协程)→ 用 Coroutine\run 包一层
        $probe = static function (): array {//[reachable, supported, version]
            try {
                $redis = get_inject_obj(Redis::class);
                $redis->ping();
            } catch (\Throwable $e) {
                return [false, false, ''];//连不上
            }
            $version = '';
            try {
                $info    = $redis->info('server');
                $version = is_array($info) ? (string) ($info['redis_version'] ?? '') : '';
            } catch (\Throwable $e) {
                //取版本失败不影响能力判定
            }
            try {
                //对探针 key 的不存在 field 执行 HEXPIRE:支持→返回数组([-2]=无此 field,不建 key);不支持(unknown command)→抛异常/非数组
                $ret = $redis->rawCommand('HEXPIRE', '__ws_hexpire_probe__', 1, 'FIELDS', 1, 'probe');
                return [true, is_array($ret), $version];
            } catch (\Throwable $e) {
                return [true, false, $version];
            }
        };

        $res = [false, false, ''];
        if (Coroutine::getCid() === -1) {
            \Swoole\Coroutine\run(function () use (&$res, $probe) {
                $res = $probe();
            });
        } else {
            $res = $probe();
        }
        [$reachable, $supported, $version] = $res;

        if (! $reachable) {
            fwrite(STDERR, '[WARN] WS 启动校验：暂时无法连接 Redis，跳过 HEXPIRE(Redis 7.4+)能力探测；WS 运行强依赖 Redis 7.4+，请确保其可用。' . PHP_EOL);
            return;
        }
        if ($supported) {
            return;//支持,放行
        }
        $this->fatal([
            '检测到 Redis 不支持 HEXPIRE（需 Redis 7.4+）。',
            '',
            '  检测到的 Redis 版本：' . ($version !== '' ? $version : '未知'),
            '',
            'WS 的在线 presence 索引与连接绑定反向索引都依赖 HEXPIRE 给 hash field 设独立 TTL（死连接自洁）；',
            'Redis < 7.4 没有该命令，继续运行会导致绑定/在线判断出错。',
            '',
            '请将 Redis 升级到 7.4+（或换用支持 HEXPIRE 的实例）后重新启动。',
        ]);
    }

    /** 打印 FATAL 框 + 终止启动(主进程、监听前,exit 可干净中止)。 */
    private function fatal(array $body): void
    {
        $lines = array_merge(
            ['', '========================================================================', '[FATAL] 启动已中止：'],
            $body,
            ['========================================================================', '']
        );
        fwrite(STDERR, implode(PHP_EOL, $lines) . PHP_EOL);
        exit(1);
    }
}
