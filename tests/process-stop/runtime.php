<?php

declare(strict_types=1);

use Dleno\CommonCore\Base\AsyncQueue\BaseDriverFactory;
use Dleno\CommonCore\Base\AsyncQueue\BaseQueueConsumer;
use Dleno\CommonCore\ConfigProvider;
use Dleno\CommonCore\Signal\ProcessStopHandler;
use Dleno\CommonCore\Tools\Http\ElasticHttpPool;
use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Crontab;
use Hyperf\Crontab\Process\CrontabDispatcherProcess;
use Hyperf\Crontab\Strategy\StrategyInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Process\Event\AfterProcessHandle;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hyperf\Signal\Listener\SignalDeregisterListener;
use Hyperf\Signal\Listener\SignalRegisterListener;
use Psr\EventDispatcher\EventDispatcherInterface;

// The dependency fixture is outside the checkout. Always load the package being tested.
$loader = require '/check/vendor/autoload.php';
$loader->addPsr4('Dleno\\CommonCore\\', '/source/src', true);
define('BASE_PATH', '/check');
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
function record(string $event, array $data = []): void
{
    file_put_contents('/out/events.jsonl', json_encode(['event' => $event, 'time' => microtime(true), 'pid' => getmypid()] + $data) . "\n", FILE_APPEND | LOCK_EX);
}
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
class StopCheckConsumer extends BaseQueueConsumer {}
class ApplicationDispatcher extends CrontabDispatcherProcess {}
class HttpJob extends Hyperf\AsyncQueue\Job
{
    public function __construct(private int $seconds) {}
    public function handle(): void
    {
        record('job-start');
        foreach (['/slow?seconds=' . $this->seconds, '/second'] as $path) {
            $response = ElasticHttpPool::request('stop-check', 'http://http:8080' . $path, '', 'GET', [], 25, ['min_connections' => 1, 'health_path' => null]);
            verify($response['statusCode'] === 200 && $response['errCode'] === 0, 'HTTP failed: ' . json_encode($response));
            record('http-ok', ['path' => $path]);
        }
        record('job-done', ['pools' => ElasticHttpPool::statistics()]);
    }
}
$provider = new ConfigProvider();
putenv('ENABLE_CRONTAB=false');
$disabled = $provider();
verify($disabled['processes'] === [], 'Disabled dispatcher registered');
putenv('ENABLE_CRONTAB=true');
$package = $provider();
verify($package['processes'] === [CrontabDispatcherProcess::class], 'Dispatcher must register exactly once');
verify($package['signal'] === ['handlers' => [ProcessStopHandler::class]], 'Signal duplicate or default timeout');
verify((new ProcessStopHandler())->listen() === [[Hyperf\Signal\SignalHandlerInterface::PROCESS, SIGTERM], [Hyperf\Signal\SignalHandlerInterface::PROCESS, SIGINT]], 'Signal scope changed');
verify((new ReflectionClass(Dleno\CommonCore\Process\CrontabDispatcherProcess::class))->getAttributes() === [], 'Dispatcher annotation added');
$definitions = $package['dependencies'];
$definitions[StdoutLoggerInterface::class] = fn () => new class extends Psr\Log\AbstractLogger implements StdoutLoggerInterface {
    public function log($level, Stringable|string $message, array $context = []): void { record('log', ['level' => $level, 'message' => (string)$message]); }
};
$definitions[StrategyInterface::class] = fn () => new class implements StrategyInterface {
    public function dispatch(Crontab $crontab) { record('dispatch'); }
};
$container = new Container(new DefinitionSource($definitions));
ApplicationContext::setContainer($container);
$config = new Config([
    'signal' => $package['signal'],
    'crontab' => ['enable' => true],
    'redis' => ['default' => ['host' => 'redis', 'port' => 6379, 'db' => 0, 'pool' => ['min_connections' => 1, 'max_connections' => 10]]],
    'async_queue' => ['default' => [
        'driver' => Hyperf\AsyncQueue\Driver\RedisDriver::class,
        'channel' => getenv('CASE'), 'timeout' => 2, 'handle_timeout' => 60,
        'retry_seconds' => 10, 'processes' => 1, 'concurrent' => ['limit' => 1],
    ]],
]);
$container->set(ConfigInterface::class, $config);
$container->set(EventDispatcherInterface::class, new class($container) implements EventDispatcherInterface {
    public function __construct(private Container $container) {}
    public function dispatch(object $event): object
    {
        if ($event instanceof BeforeProcessHandle) {
            (new SignalRegisterListener($this->container))->process($event);
            $handlers = $this->container->get(Hyperf\Signal\SignalManager::class)->getHandlers();
            verify(!isset($handlers[Hyperf\Signal\SignalHandlerInterface::WORKER]), 'HTTP worker scope registered');
            foreach ([SIGTERM, SIGINT] as $signal) {
                verify(count($handlers[Hyperf\Signal\SignalHandlerInterface::PROCESS][$signal]) === 1, 'Duplicate signal handler');
            }
            Swoole\Coroutine::create(function () {
                Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->yield();
                record('exit-coordinator', ['running' => Hyperf\Process\ProcessManager::isRunning()]);
            });
            register_shutdown_function(fn () => record('process-exit'));
            record('process-ready', ['class' => get_class($event->process), 'to_next_minute' => 60 - fmod(microtime(true), 60)]);
        }
        if ($event instanceof AfterProcessHandle) {
            (new SignalDeregisterListener($this->container))->process($event);
            record('handle-returned');
        }
        if ($event instanceof Hyperf\AsyncQueue\Event\AfterHandle) record('acked', ['result' => $event->result->name]);
        if ($event instanceof Hyperf\AsyncQueue\Event\FailedHandle || $event instanceof Hyperf\AsyncQueue\Event\RetryHandle) record('job-error');
        return $event;
    }
});
$resolved = $container->get(CrontabDispatcherProcess::class);
verify(get_class($resolved) === Dleno\CommonCore\Process\CrontabDispatcherProcess::class, 'Package dependency not resolved');
verify($resolved->getInterval(5, 0.25) === 54.75, 'Minute interval changed');
// The same precedence used by DefinitionSourceFactory: app dependencies replace provider bindings.
$appDefinitions = array_replace($definitions, [CrontabDispatcherProcess::class => ApplicationDispatcher::class]);
$appContainer = new Container(new DefinitionSource($appDefinitions));
$appContainer->set(ConfigInterface::class, $config);
$appContainer->set(EventDispatcherInterface::class, $container->get(EventDispatcherInterface::class));
verify(get_class($appContainer->get(CrontabDispatcherProcess::class)) === ApplicationDispatcher::class, 'Application override lost');
foreach ([StopCheckConsumer::class, Dleno\CommonCore\Process\CrontabDispatcherProcess::class,
    Dleno\CommonCore\Websocket\Process\DcsMessageConsumer::class, Dleno\CommonCore\Websocket\Process\DcsControlConsumer::class] as $class) {
    verify((new ReflectionClass($class))->getDefaultProperties()['recvTimeout'] === 1.0, 'Pipe timeout not inherited');
}
record('registration-ok', ['php' => PHP_VERSION, 'swoole' => swoole_version()]);
$process = getenv('MODE') === 'cron' ? $resolved : $container->get(StopCheckConsumer::class);
if ((int)getenv('SECONDS') > 0) {
    Swoole\Coroutine\run(function () use ($container) {
        verify($container->get(BaseDriverFactory::class)->get('default')->push(new HttpJob((int)getenv('SECONDS'))), 'Push failed');
    });
}
$server = new Swoole\Http\Server('127.0.0.1', 9501, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'max_wait_time' => 30, 'log_file' => '/out/swoole.log']);
$server->on('request', fn ($request, $response) => $response->end('ok'));
Hyperf\Process\ProcessManager::setRunning(true); // Normally set by Hyperf's BootProcessListener.
$process->bind($server); // Exercise the inherited bindServer/listen/restart flow, without copying it.
$server->start();
record('server-exit');
