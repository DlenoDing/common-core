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
use Hyperf\Crontab\Strategy\WorkerStrategy;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Process\Event\AfterProcessHandle;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hyperf\Process\ProcessManager;
use Hyperf\Redis\RedisFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

$loader = require '/check/vendor/autoload.php';
$loader->addPsr4('Dleno\\CommonCore\\', '/source/src', true);
define('BASE_PATH', '/check');
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
function record(string $event, array $data = []): void
{
    // One append per record. A synchronous lifecycle callback must never flock a coroutine's yielded file IO.
    file_put_contents('/out/events.jsonl', json_encode(['event' => $event, 'time' => microtime(true), 'pid' => getmypid()] + $data) . "\n", FILE_APPEND);
}
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
class LifecycleSignalObserver implements Hyperf\Signal\SignalHandlerInterface
{
    public function listen(): array { return [[self::PROCESS, SIGTERM], [self::PROCESS, SIGINT]]; }
    public function handle(int $signal): void { record('observed-stop-signal', ['signal' => $signal, 'running' => ProcessManager::isRunning()]); }
}
class LifecycleReadProbe extends Hyperf\Amqp\IO\SwooleIO
{
    private bool $reportedClose = false;
    public function read($len): string {
        try { return parent::read($len); }
        catch (Throwable $error) {
            record('amqp-read-error', ['message' => $error->getMessage(), 'closing' => Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->isClosing()]);
            throw $error;
        }
    }
    public function close(): void {
        parent::close();
        if (!$this->reportedClose) {
            $this->reportedClose = true;
            record('amqp-io-closed', ['cid' => Swoole\Coroutine::getCid(), 'closing' => Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->isClosing()]);
        }
    }
}
class RecycledCronJob { public function run(): void { business('1', 1, true); } }
class StopCheckConsumer extends BaseQueueConsumer {}
class SharedConsumeProcess extends Hyperf\Process\AbstractProcess
{
    protected float $recvTimeout = 1.0;
    public function handle(): void
    {
        $done = new Hyperf\Engine\Channel(2);
        foreach (['fast', 'slow'] as $name) {
            Hyperf\Coroutine\Coroutine::create(function () use ($name, $done) {
                $message = (new CheckAmqpMessage())->setQueue(getenv('CASE') . '-' . $name)->setExchange(getenv('CASE') . '-' . $name)->setRoutingKey(getenv('CASE') . '-' . $name)->setMaxConsumption(1);
                $this->container->get(Hyperf\Amqp\Consumer::class)->consume($message);
                record('shared-consumer-returned', ['name' => $name]);
                $done->push(true);
            });
        }
        $done->pop(); $done->pop();
    }
}
// Exercise the shared-driver lifecycle used by multiple consume coroutines.
class SharedRedisConsumeProcess extends Hyperf\Process\AbstractProcess
{
    protected float $recvTimeout = 1.0;
    public function handle(): void
    {
        $driver = $this->container->get(BaseDriverFactory::class)->get('default');
        $done = new Hyperf\Engine\Channel(2);
        foreach (['fast', 'slow'] as $name) {
            Hyperf\Coroutine\Coroutine::create(function () use ($driver, $name, $done) {
                $driver->consume();
                record('shared-consumer-returned', ['name' => $name]);
                $done->push(true);
            });
            if ($name === 'fast') Swoole\Coroutine::sleep(.1);
        }
        $done->pop(); $done->pop();
    }
}
// A legal callback decorator makes the prepared-but-not-started interval observable.
class DelayedCallbackDriver extends Dleno\CommonCore\Base\AsyncQueue\RedisDriver
{
    protected function getCallback($data, $message): callable
    {
        $callback = parent::getCallback($data, $message);
        return function () use ($callback) {
            record('callback-pending');
            Swoole\Coroutine::sleep(1);
            return $callback();
        };
    }
}
class InheritedPackageFactory extends Dleno\CommonCore\Base\Amqp\ConsumerFactory {}
class FactoryConsumer extends Dleno\CommonCore\Base\Amqp\Consumer { public array $factoryParameters = []; }
class OverridingPackageFactory extends InheritedPackageFactory
{
    public int $calls = 0;
    public function __invoke(Psr\Container\ContainerInterface $c, array $parameters = []): Hyperf\Amqp\Consumer
    {
        ++$this->calls;
        verify(get_class(parent::__invoke($c, $parameters)) === Dleno\CommonCore\Base\Amqp\Consumer::class, 'Factory parent delegation failed');
        $consumer = new FactoryConsumer($c, $c->get(Hyperf\Amqp\ConnectionFactory::class), new CheckLogger());
        $consumer->factoryParameters = $parameters;
        return $consumer;
    }
}
class ApplicationDispatcher extends CrontabDispatcherProcess {}
class AppWorkerExitCallback extends Hyperf\Framework\Bootstrap\WorkerExitCallback {}
class FutureWorkerCronJob { public function run(): void { record('future-worker-task'); } }
class WorkerCronJob { public function run(string $id): void { business($id, (int)getenv('SECONDS') + (getenv('STRATEGY') === 'process' && $id === '1' ? 7 : 0), true); } }
class CustomStrategy extends WorkerStrategy {}
class BoundServer extends Hyperf\Server\Server { public function attach(Swoole\Server $server): void { $this->server = $server; } }
class MinuteProbe { public function run(): void { record('minute-task', ['second' => (int)date('s')]); } }
class AppRedisDriver extends Hyperf\AsyncQueue\Driver\RedisDriver {}
class AppAmqpConsumer extends Hyperf\Amqp\Consumer { public array $factoryParameters = []; }
class AppConnectionFactory extends Hyperf\Amqp\ConnectionFactory {}
class InheritedConnectionFactory extends Dleno\CommonCore\Base\Amqp\ConnectionFactory {}
class AppExecutor extends Hyperf\Crontab\Strategy\Executor {}
class AppAmqpFactory extends Hyperf\Amqp\ConsumerFactory
{
    public function __invoke(Psr\Container\ContainerInterface $c, array $parameters = []): Hyperf\Amqp\Consumer { $consumer = new AppAmqpConsumer($c, $c->get(Hyperf\Amqp\ConnectionFactory::class), new CheckLogger()); $consumer->factoryParameters = $parameters; return $consumer; }
}
class BoundaryJob
{
    public function run(): void
    {
        $c = ApplicationContext::getContainer();
        $cron = (new Crontab())->setName('locked-boundary')->setRule('0 * * * * *')->setMutexExpires(2);
        $task = $c->get(Hyperf\Crontab\Mutex\TaskMutex::class);
        $server = $c->get(Hyperf\Crontab\Mutex\ServerMutex::class);
        $owner = $server->get($cron);
        verify($owner !== '' && $task->exists($cron), 'Boundary task entered without locks');
        record('boundary-job-start');
        business('0', (int)getenv('SECONDS'), true);
        verify($task->exists($cron) && $server->get($cron) === $owner, 'Outer executor mutex lost');
        record('boundary-locks-retained');
    }
}
class Providers extends Hyperf\Config\ProviderConfig
{
    public static function mergeProviders(array $providers): array { return parent::loadProviders($providers); }
}
class CheckLogger extends Psr\Log\AbstractLogger implements StdoutLoggerInterface
{
    public function log($level, Stringable|string $message, array $context = []): void { record('log', ['level' => $level, 'message' => (string)$message]); }
}
class CheckAmqpMessage extends Dleno\CommonCore\Base\Amqp\BaseConsumer
{
    public function __construct() { $this->setMaxConsumption((int)getenv('MAX_CONSUMPTION')); $this->qos = ['prefetch_size' => 0, 'prefetch_count' => (int)(getenv('LIMIT') ?: 1), 'global' => false]; }
    public function getQueueBuilder(): Hyperf\Amqp\Builder\QueueBuilder { return parent::getQueueBuilder()->setArguments([]); }
    public function unserialize(string $data) { return json_decode($data, true, flags: JSON_THROW_ON_ERROR); }
    public function consumeMessage($data, PhpAmqpLib\Message\AMQPMessage $message): Hyperf\Amqp\Result
    { record('amqp-delivery', ['id' => $data['id'], 'tag' => $message->getDeliveryTag(), 'redelivered' => $message->isRedelivered()]); return parent::consumeMessage($data, $message); }
    public function consume($data): Hyperf\Amqp\Result { business($data['id'], $data['seconds'], (bool)getenv('RESOURCES')); return Hyperf\Amqp\Result::ACK; }
}
class HttpJob extends Hyperf\AsyncQueue\Job
{
    public function __construct(private string $id, private int $seconds, private bool $resources = false, private bool $retry = false) {}
    public function handle(): void
    {
        $nested = getenv('NESTED');
        if ($nested) {
            record('job-start', ['id' => $this->id]);
            $done = new Hyperf\Engine\Channel(1);
            Hyperf\Coroutine\parallel([function () use ($done) {
                Hyperf\Coroutine\Coroutine::defer(function () use ($done) {
                    record('nested-defer-start', ['id' => $this->id]);
                    Swoole\Coroutine::sleep(3);
                    record('nested-defer-done', ['id' => $this->id, 'closing' => Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->isClosing()]);
                    $done->push(true);
                });
            }]);
            record('nested-parallel-returned', ['id' => $this->id]);
            if ($nested === 'barrier') {
                $done->pop();
                record('nested-barrier-returned', ['id' => $this->id]);
            }
        }
        business($this->id, $this->seconds, $this->resources, !$nested);
        if ($this->retry) throw new RuntimeException('expected-retry');
    }
}
function business(string $id, int $seconds, bool $resources, bool $announce = true): void
{
    if ($announce) record('job-start', ['id' => $id]);
    if (getenv('DEFER')) {
        Hyperf\Coroutine\Coroutine::defer(function () use ($id) {
            record('task-defer-start', ['id' => $id]);
            Swoole\Coroutine::sleep(1);
            verify(!Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->isClosing(), 'Resources closed inside task defer');
            $response = ElasticHttpPool::request('stop-check', 'http://http:8080/deferred', '', 'GET', [], 5, ['min_connections' => 1, 'health_path' => null]);
            verify($response['statusCode'] === 200, 'Deferred HTTP failed');
            record('task-defer-done', ['id' => $id]);
        });
    }
    $c = ApplicationContext::getContainer();
    if ($resources) {
        Hyperf\Coroutine\Coroutine::defer(function () use ($id, $c) {
            verify(!$c->get(RedisFactory::class)->get('default')->get('{auditlong-' . getenv('CASE') . '-' . $id . '}'), 'Long TTL DcsLock was not unlocked by defer');
            record('locks-unlocked', ['id' => $id]);
        });
        $factory = $c->get(RedisFactory::class);
        $cron = (new Crontab())->setName(getenv('CASE') . '-' . $id)->setRule('* * * * *')->setMutexExpires(2);
        $task = new Hyperf\Crontab\Mutex\RedisTaskMutex($factory);
        Hyperf\Crontab\Mutex\RedisServerMutexByNodeName::setNodeName('owner');
        $server = new Hyperf\Crontab\Mutex\RedisServerMutexByNodeName($factory);
        verify($task->create($cron) && $server->attempt($cron), 'Could not acquire framework locks');
        verify(Dleno\CommonCore\Tools\Lock\DcsLock::lock('audit-' . getenv('CASE') . '-' . $id, 'owner', 5), 'Could not acquire DcsLock');
        verify(Dleno\CommonCore\Tools\Lock\DcsLock::lock('auditlong-' . getenv('CASE') . '-' . $id, 'owner', 30), 'Could not acquire long DcsLock');
        Hyperf\Cache\Collector\Memory::instance()->set('cache-' . $id, 'retained');
        // This connection must survive both the stop signal and the consumer loop returning.
        $connection = $c->get(Hyperf\Amqp\ConnectionFactory::class)->getConnection('default');
        $channel = $connection->getConfirmChannel();
        $channel->queue_declare('publish-' . getenv('CASE'), false, true, false, false);
        record('resources-acquired', ['id' => $id]);
    }
    foreach (['/slow?seconds=' . $seconds, '/second'] as $path) {
        $response = ElasticHttpPool::request('stop-check', 'http://http:8080' . $path, '', 'GET', [], 30, ['min_connections' => 1, 'health_path' => null]);
        verify($response['statusCode'] === 200 && $response['errCode'] === 0, 'HTTP failed: ' . json_encode($response));
        record('http-ok', ['id' => $id, 'path' => $path]);
    }
    if ($resources) {
        verify(Hyperf\Cache\Collector\Memory::instance()->get('cache-' . $id) === 'retained', 'Memory cleared before completion');
        verify($task->exists($cron), 'Task mutex expired before completion');
        verify($server->get($cron) === 'owner', 'Server mutex released before completion');
        $redis = $factory->get('default');
        verify($redis->get('{audit-' . getenv('CASE') . '-' . $id . '}') === 'owner', 'DcsLock expired before completion');
        $confirmed = false;
        $channel->set_ack_handler(function () use (&$confirmed) { $confirmed = true; });
        $channel->basic_publish(new PhpAmqpLib\Message\AMQPMessage($id), '', 'publish-' . getenv('CASE'));
        if (getenv('BROKER_OUTAGE')) record('publish-confirm-wait', ['id' => $id]);
        $channel->wait_for_pending_acks_returns(getenv('BROKER_OUTAGE') === 'pause-resume' ? 20 : 5);
        if (getenv('BROKER_OUTAGE')) record('publish-confirmed', ['id' => $id]);
        verify($confirmed, 'Post-stop AMQP publish was not confirmed');
        record('resources-retained', ['id' => $id]);
        $task->remove($cron);
    }
    record('job-done', ['id' => $id]);
}
$provider = new ConfigProvider();
putenv('ENABLE_CRONTAB=false');
verify($provider()['processes'] === [], 'Disabled dispatcher registered');
putenv('ENABLE_CRONTAB=true');
$package = $provider();
verify($package['processes'] === [CrontabDispatcherProcess::class], 'Dispatcher must register exactly once');
verify($package['signal'] === ['handlers' => [ProcessStopHandler::class]], 'Signal duplicate or default timeout');
verify((new ProcessStopHandler())->listen() === [[2, SIGTERM], [2, SIGINT]], 'Signal scope changed');
verify((new ReflectionClass(Dleno\CommonCore\Process\CrontabDispatcherProcess::class))->getAttributes() === [], 'Dispatcher annotation added');
// Exercise the real provider merge in both orders, including hyperf/amqp's own Consumer factory binding.
foreach ([[ConfigProvider::class, Hyperf\Amqp\ConfigProvider::class], [Hyperf\Amqp\ConfigProvider::class, ConfigProvider::class]] as $order) {
    $deps = Providers::mergeProviders($order)['dependencies'];
    $test = new Container(new DefinitionSource($deps));
    $test->set(ConfigInterface::class, new Config(['redis' => ['default' => []]]));
    $test->set(StdoutLoggerInterface::class, new CheckLogger());
    verify(get_class($test->get(Hyperf\Amqp\Consumer::class)) === Dleno\CommonCore\Base\Amqp\Consumer::class, 'AMQP provider precedence failed');
}
$frameworkProviders = Hyperf\Support\Composer::getMergedExtra('hyperf')['config'] ?? [];
$merged = Providers::mergeProviders(array_values(array_unique(array_merge([ConfigProvider::class], $frameworkProviders))));
$defs = $merged['dependencies'];
if (getenv('UNSAFE_STOP')) {
    // Negative control only: reproduce v3.2.3 without editing package/vendor files.
    $defs[ProcessStopHandler::class] = fn () => new class extends ProcessStopHandler {
        public function handle(int $signal): void { parent::handle($signal); Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->resume(); }
    };
}
$defs[StdoutLoggerInterface::class] = CheckLogger::class;
$defs[StrategyInterface::class] = match (getenv('STRATEGY')) {
    'task' => Hyperf\Crontab\Strategy\TaskWorkerStrategy::class,
    'process' => Hyperf\Crontab\Strategy\ProcessStrategy::class,
    'coroutine' => Hyperf\Crontab\Strategy\CoroutineStrategy::class,
    'custom' => CustomStrategy::class,
    default => WorkerStrategy::class,
};
$defs[Hyperf\ExceptionHandler\Formatter\FormatterInterface::class] = Hyperf\ExceptionHandler\Formatter\DefaultFormatter::class;
$c = new Container(new DefinitionSource($defs));
ApplicationContext::setContainer($c);
$mode = getenv('MODE');
$case = getenv('CASE');
$limit = (int)(getenv('LIMIT') ?: 1);
$config = new Config([
    'signal' => $package['signal'], 'processes' => $mode === 'cron' ? $package['processes'] : [], 'crontab' => ['enable' => true],
    'redis' => ['default' => ['host' => 'redis', 'port' => 6379, 'db' => 0, 'pool' => ['min_connections' => 1, 'max_connections' => 20]]],
    'async_queue' => ['default' => ['driver' => Hyperf\AsyncQueue\Driver\RedisDriver::class, 'channel' => $case,
        'timeout' => 2, 'handle_timeout' => 60, 'retry_seconds' => 60, 'processes' => 1, 'concurrent' => ['limit' => $limit],
        'max_messages' => (int)getenv('MAX_MESSAGES')]],
    'amqp' => ['default' => ['host' => 'rabbit', 'user' => 'audit', 'password' => 'isolated-test-only', 'pool' => ['connections' => (int)(getenv('CONNECTIONS') ?: 1)],
        'concurrent' => ['limit' => $limit], 'params' => ['heartbeat' => (int)(getenv('HEARTBEAT') === false || getenv('HEARTBEAT') === '' ? 10 : getenv('HEARTBEAT')), 'read_write_timeout' => 25, 'connection_timeout' => 5]]],
]);
if (getenv('HEARTBEAT') === 'omit') {
    $params = $config->get('amqp.default.params');
    unset($params['heartbeat']);
    $config->set('amqp.default.params', $params);
}
if (getenv('MINIMAL_PARAMS')) {
    $params = $config->get('amqp.default.params');
    unset($params['read_write_timeout'], $params['connection_timeout']);
    $config->set('amqp.default.params', $params);
}
if (getenv('CUSTOM_IO')) {
    $config->set('amqp.default.io', function (array $values, Hyperf\Amqp\Params $params) {
        record('custom-io', ['heartbeat' => $params->getHeartbeat(), 'read_write_timeout' => $params->getReadWriteTimeout(), 'connection_timeout' => $params->getConnectionTimeout()]);
        if (getenv('BROKER_OUTAGE')) return new LifecycleReadProbe($values['host'], (int)($values['port'] ?? 5672), $params->getConnectionTimeout(), $params->getReadWriteTimeout(), (bool)($values['open_ssl'] ?? false));
        return (new Hyperf\Amqp\IO\IOFactory())->create($values, $params);
    });
}
if (getenv('SERIAL')) $config->set('async_queue.default.concurrent', []);
$c->set(ConfigInterface::class, $config);
if (getenv('REPEAT_SIGNAL_OBSERVER')) $config->set('signal.handlers', array_merge($config->get('signal.handlers'), [LifecycleSignalObserver::class => -1]));
$config->set('listeners', array_merge([
    Hyperf\Signal\Listener\SignalRegisterListener::class => PHP_INT_MAX,
    Hyperf\Signal\Listener\SignalDeregisterListener::class,
], $package['listeners']));
$listeners = (new Hyperf\Event\ListenerProviderFactory())($c);
$c->set(EventDispatcherInterface::class, new Hyperf\Event\EventDispatcher($listeners));
// Exercise a connection created in an early process initialization listener.
$listeners->on(BeforeProcessHandle::class, function ($event) use ($c) {
    if (!getenv('CHECK_CONNECTION')) return;
    $connection = $c->get(Hyperf\Amqp\ConnectionFactory::class)->getConnection('default');
    record('connection-config', ['heartbeat' => $connection->getHeartbeat()]);
    if (getenv('EARLY_FAULT')) {
        Swoole\Coroutine::create(function () {
            Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->yield();
            record('exit-coordinator');
        });
        register_shutdown_function(fn () => record('process-exit', ['pools' => ElasticHttpPool::statistics(), 'last_error' => error_get_last()]));
        record('process-ready', ['class' => get_class($event->process)]);
        throw new RuntimeException('expected-process-setup-fault');
    }
}, PHP_INT_MAX - 1);
$listeners->on(BeforeProcessHandle::class, function ($event) use ($c) {
    $handlers = $c->get(Hyperf\Signal\SignalManager::class)->getHandlers();
    foreach ([SIGTERM, SIGINT] as $signal) {
        verify(count(array_filter($handlers[2][$signal], static fn ($handler) => $handler instanceof ProcessStopHandler)) === 1, 'Duplicate package stop handler');
        verify(count($handlers[2][$signal]) === (getenv('REPEAT_SIGNAL_OBSERVER') ? 2 : 1), 'Unexpected signal handler');
    }
    verify(!isset($handlers[1]), 'HTTP worker handler registered');
    Swoole\Coroutine::create(function () {
        Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->yield();
        record('exit-coordinator', ['running' => ProcessManager::isRunning()]);
    });
    Swoole\Coroutine::create(function () {
        $exit = Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT);
        while (ProcessManager::isRunning()) { if ($exit->yield(.01)) break; }
        if (!ProcessManager::isRunning()) record('running-false');
    });
    register_shutdown_function(fn () => record('process-exit', ['pools' => ElasticHttpPool::statistics(), 'last_error' => error_get_last()]));
    record('process-ready', ['class' => get_class($event->process), 'to_next_minute' => 60 - fmod(microtime(true), 60)]);
});
$listeners->on(AfterProcessHandle::class, fn () => record('handle-returned', ['signal_manager_stopped' => $c->get(Hyperf\Signal\SignalManager::class)->isStopped()]));
$listeners->on(Hyperf\AsyncQueue\Event\AfterHandle::class, fn () => record('acked'));
$listeners->on(Hyperf\AsyncQueue\Event\RetryHandle::class, fn () => record('retried'));
$listeners->on(Hyperf\AsyncQueue\Event\FailedHandle::class, fn () => record('job-error'));
$listeners->on(Hyperf\Amqp\Event\AfterConsume::class, fn () => record('amqp-business-returned'));
$listeners->on(Hyperf\Amqp\Event\FailToConsume::class, fn () => record('job-error'));
// Class mapping and application overrides, using the same array_replace precedence as DefinitionSourceFactory.
foreach ([Hyperf\Amqp\ConnectionFactory::class => Dleno\CommonCore\Base\Amqp\ConnectionFactory::class,
    CrontabDispatcherProcess::class => Dleno\CommonCore\Process\CrontabDispatcherProcess::class,
    Hyperf\Amqp\Consumer::class => Dleno\CommonCore\Base\Amqp\Consumer::class,
    Hyperf\Framework\Bootstrap\WorkerExitCallback::class => Dleno\CommonCore\Process\WorkerExitCallback::class,
    Hyperf\Crontab\Strategy\Executor::class => Dleno\CommonCore\Process\CrontabExecutor::class] as $key => $class) {
    verify(get_class($c->get($key)) === $class, 'Default binding lost: ' . $key);
    verify(str_starts_with((new ReflectionClass($class))->getFileName(), '/source/'), 'Loaded installed package instead of checkout');
}
verify(get_class($c->get(BaseDriverFactory::class)->get('default')) === Dleno\CommonCore\Base\AsyncQueue\RedisDriver::class, 'Queue driver not drained');
$appDefs = array_replace($defs, [CrontabDispatcherProcess::class => ApplicationDispatcher::class,
    Hyperf\Framework\Bootstrap\WorkerExitCallback::class => AppWorkerExitCallback::class,
    Hyperf\AsyncQueue\Driver\RedisDriver::class => AppRedisDriver::class, Hyperf\Crontab\Strategy\Executor::class => AppExecutor::class,
    Hyperf\Amqp\Consumer::class => fn ($container) => new AppAmqpConsumer($container, $container->get(Hyperf\Amqp\ConnectionFactory::class), new CheckLogger())]);
$app = new Container(new DefinitionSource($appDefs));
$app->set(ConfigInterface::class, $config);
$app->set(EventDispatcherInterface::class, $c->get(EventDispatcherInterface::class));
foreach ([Hyperf\Framework\Bootstrap\WorkerExitCallback::class => AppWorkerExitCallback::class, CrontabDispatcherProcess::class => ApplicationDispatcher::class, Hyperf\Amqp\Consumer::class => AppAmqpConsumer::class, Hyperf\Crontab\Strategy\Executor::class => AppExecutor::class] as $key => $class) verify(get_class($app->get($key)) === $class, 'Application override lost');
$factoryApp = new Container(new DefinitionSource(array_replace($defs, [Hyperf\Amqp\ConsumerFactory::class => fn () => new AppAmqpFactory()])));
$factoryApp->set(ConfigInterface::class, $config);
$factoryApp->set(EventDispatcherInterface::class, $c->get(EventDispatcherInterface::class));
verify(get_class($factoryApp->get(Hyperf\Amqp\Consumer::class)) === AppAmqpConsumer::class, 'Existing app ConsumerFactory override lost');
verify($factoryApp->make(Hyperf\Amqp\Consumer::class, ['probe' => 'retained'])->factoryParameters === ['probe' => 'retained'], 'Factory parameters lost');
foreach ([new InheritedPackageFactory(), new OverridingPackageFactory()] as $factory) {
    $inherited = new Container(new DefinitionSource(array_replace($defs, [Hyperf\Amqp\ConsumerFactory::class => fn () => $factory])));
    $inherited->set(ConfigInterface::class, $config);
    $inherited->set(EventDispatcherInterface::class, $c->get(EventDispatcherInterface::class));
    $expected = $factory instanceof OverridingPackageFactory ? FactoryConsumer::class : Dleno\CommonCore\Base\Amqp\Consumer::class;
    verify(get_class($inherited->get(Hyperf\Amqp\Consumer::class)) === $expected, 'Inherited package Factory recursed or lost override');
    $made = $inherited->make(Hyperf\Amqp\Consumer::class, ['probe' => 'inherited']);
    verify(get_class($made) === $expected, 'Inherited Factory make failed');
    if ($factory instanceof OverridingPackageFactory) {
        verify($factory->calls === 2 && $made->factoryParameters === ['probe' => 'inherited'], 'Factory override invocation or parameters lost');
    }
}
foreach ([AppConnectionFactory::class, InheritedConnectionFactory::class] as $factoryClass) {
    $factoryContainer = new Container(new DefinitionSource(array_replace($defs, [Hyperf\Amqp\ConnectionFactory::class => $factoryClass])));
    $factoryContainer->set(ConfigInterface::class, $config);
    verify(get_class($factoryContainer->get(Hyperf\Amqp\ConnectionFactory::class)) === $factoryClass, 'Application ConnectionFactory override lost');
}
record('factory-inheritance-ok');
verify(get_class($app->make(Hyperf\AsyncQueue\Driver\RedisDriver::class, ['config' => $config->get('async_queue.default')])) === AppRedisDriver::class, 'App Redis driver override lost');
verify($c->get(CrontabDispatcherProcess::class)->getInterval(5, .25) === 54.75, 'Minute interval changed');
foreach ([StopCheckConsumer::class, Dleno\CommonCore\Process\CrontabDispatcherProcess::class,
    Dleno\CommonCore\Websocket\Process\DcsMessageConsumer::class, Dleno\CommonCore\Websocket\Process\DcsControlConsumer::class] as $class) {
    verify((new ReflectionClass($class))->getDefaultProperties()['recvTimeout'] === 1.0, 'Pipe timeout not inherited');
}
record('registration-ok', ['php' => PHP_VERSION, 'swoole' => swoole_version()]);
$seconds = (int)getenv('SECONDS');
$jobs = (int)(getenv('JOBS') ?: ($seconds > 0 ? 1 : 0));
if (getenv('PENDING_START')) {
    $driverConfig = $config->get('async_queue.default');
    $driverConfig['driver'] = DelayedCallbackDriver::class;
    $c->get(BaseDriverFactory::class)->set('default', $driverConfig);
}
if (in_array($mode, ['queue', 'redis-shared'], true) && $jobs) {
    Swoole\Coroutine\run(function () use ($c, $jobs, $seconds) {
        $driver = $c->get(BaseDriverFactory::class)->get('default');
        for ($i = 0; $i < $jobs; ++$i) {
            $id = getenv('MODE') === 'redis-shared' ? ($i < 2 ? 'fast-' : 'slow-') . $i : (string)$i;
            $duration = getenv('MODE') === 'redis-shared' ? ($i < 2 ? 1 : 4) : $seconds;
            $job = new HttpJob($id, $duration, (bool)getenv('RESOURCES'), (bool)getenv('RETRY'));
            if (getenv('RETRY')) $job->setMaxAttempts(1);
            verify($driver->push($job), 'Push failed');
        }
    });
}
if (in_array($mode, ['amqp', 'shared'], true)) {
    Swoole\Coroutine\run(function () use ($case, $jobs, $seconds) {
    $native = new PhpAmqpLib\Connection\AMQPStreamConnection('rabbit', 5672, 'audit', 'isolated-test-only');
    $ch = $native->channel();
    if (getenv('MODE') === 'shared') {
        foreach (['fast' => 2, 'slow' => 7] as $name => $duration) {
            $ch->queue_declare($case . '-' . $name, false, true, false, false);
            $ch->basic_publish(new PhpAmqpLib\Message\AMQPMessage(json_encode(['id' => $name, 'seconds' => $duration])), '', $case . '-' . $name);
        }
    }
    $ch->queue_declare($case, false, true, false, false);
    for ($i = 0; getenv('MODE') !== 'shared' && $i < $jobs; ++$i) $ch->basic_publish(new PhpAmqpLib\Message\AMQPMessage(json_encode(['id' => (string)$i, 'seconds' => $seconds])), '', $case);
    $ch->close(); $native->close();
    });
    $message = new CheckAmqpMessage();
    $message->setQueue($case)->setExchange($case)->setRoutingKey($case);
    $c->set(CheckAmqpMessage::class, $message);
    Hyperf\Di\Annotation\AnnotationCollector::collectClass(CheckAmqpMessage::class, Hyperf\Amqp\Annotation\Consumer::class, new Hyperf\Amqp\Annotation\Consumer(exchange: $case, routingKey: $case, queue: $case, name: 'stop-check'));
    if ($mode === 'amqp') $c->get(Hyperf\Amqp\ConsumerManager::class)->run();
}
$server = new Swoole\Http\Server('127.0.0.1', 9501, getenv('SERVER_MODE') === 'process' ? SWOOLE_PROCESS : SWOOLE_BASE);
$settings = ['worker_num' => 1, 'max_wait_time' => (int)(getenv('WORKER_WAIT') ?: 30), 'log_file' => '/out/swoole.log'];
if (getenv('WORKER_CRON') && in_array(getenv('STRATEGY'), ['task', 'process'], true)) {
    $settings += ['task_worker_num' => 1, 'task_enable_coroutine' => true];
    $server->on('task', static function (Swoole\Server $server, Swoole\Server\Task $task) { $task->finish(null); });
}
if (getenv('RECYCLE')) $settings += ['max_request' => 1, 'max_request_grace' => 0, 'reload_async' => true];
$server->set($settings);
$server->on('request', function ($request, $response) use ($server) {
    if (getenv('RECYCLE')) record('rotation-request', ['uri' => $request->server['request_uri']]);
    $response->end(getenv('RECYCLE') ? json_encode(['pid' => getmypid(), 'worker_id' => $server->worker_id]) : 'ok');
});
$server->on('pipeMessage', function ($server, $from, $data) use ($c) {
    $c->get(Hyperf\Crontab\Listener\OnPipeMessageListener::class)->process(new Hyperf\Framework\Event\OnPipeMessage($server, $from, $data));
});
$workerExit = $c->get(Hyperf\Framework\Bootstrap\WorkerExitCallback::class);
$server->on('workerExit', function ($server, $workerId) use ($workerExit) {
    record('native-worker-exit', ['worker_id' => $workerId, 'cid' => Swoole\Coroutine::getCid(), 'running' => Dleno\CommonCore\Process\CrontabExecutor::running()]);
    $workerExit->onWorkerExit($server, $workerId);
});
$listeners->on(Hyperf\Framework\Event\OnWorkerExit::class, function ($event) {
    record('parent-worker-exit', ['worker_id' => $event->workerId, 'cid' => Swoole\Coroutine::getCid(), 'running' => Dleno\CommonCore\Process\CrontabExecutor::running()]);
});
if (getenv('WORKER_CRON')) {
    $server->on('workerStart', function ($server, $workerId) use ($c) {
        record('worker-start', ['worker_id' => $workerId, 'running_count' => Dleno\CommonCore\Process\CrontabExecutor::running(), 'worker_signal_handlers' => count($c->get(Hyperf\Signal\SignalManager::class)->getHandlers()[1] ?? [])]);
        register_shutdown_function(fn () => record('worker-process-exit', ['worker_id' => $workerId]));
        Swoole\Coroutine::create(function () {
            Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->yield();
            record('worker-coordinator');
        });
        if (getenv('RECYCLE')) file_put_contents('/out/worker-' . $workerId . '.pid', (string)getmypid());
    });
}
if ($mode === 'publisher') {
    $server->on('workerStart', function () use ($c, $seconds, $case) {
        register_shutdown_function(fn () => record('worker-process-exit'));
        Swoole\Coroutine::create(function () {
            Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->yield();
            record('exit-coordinator');
        });
        Swoole\Coroutine::create(function () use ($c, $case) {
            $connection = $c->get(Hyperf\Amqp\ConnectionFactory::class)->getConnection('default');
            verify($connection->getHeartbeat() === 15, 'Default heartbeat not applied in worker');
            record('connection-config', ['heartbeat' => $connection->getHeartbeat()]);
            $channel = $connection->getConfirmChannel();
            $channel->queue_declare('publish-' . $case, false, true, false, false);
            $confirmed = false;
            $channel->set_ack_handler(function () use (&$confirmed) { $confirmed = true; });
            $channel->basic_publish(new PhpAmqpLib\Message\AMQPMessage('before-exit'), '', 'publish-' . $case);
            $channel->wait_for_pending_acks_returns(5);
            verify($confirmed, 'Worker publish before exit was not confirmed');
            record('worker-publish-confirmed');
            record('worker-ready');
            Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->yield();
            $deadline = microtime(true) + 10;
            while ($connection->isConnected() && microtime(true) < $deadline) Swoole\Coroutine::sleep(.01);
            verify(!$connection->isConnected(), 'Framework heartbeat did not close worker connection');
            record('worker-heartbeat-closed');
        });
    });
}
$bound = new BoundServer($c, $c->get(StdoutLoggerInterface::class), $c->get(EventDispatcherInterface::class));
$bound->attach($server);
$c->get(Hyperf\Server\ServerFactory::class)->setServer($bound);
if (getenv('BOUNDARY_BATCH')) {
    $manager = $c->get(Hyperf\Crontab\CrontabManager::class);
    $manager->register((new Crontab())->setName('locked-boundary')->setRule('0 * * * * *')->setSingleton(true)->setOnOneServer(true)->setMutexExpires(2)->setCallback([BoundaryJob::class, 'run']));
    $manager->register((new Crontab())->setName('future-probe')->setType('closure')->setRule('30 * * * * *')->setCallback(fn () => record('future-task')));
}
if (getenv('BOUNDARY')) {
    $c->get(Hyperf\Crontab\CrontabManager::class)->register((new Crontab())->setName('minute-probe')->setRule('0 * * * * *')->setCallback([MinuteProbe::class, 'run']));
}
if (getenv('WORKER_CRON')) {
    $listeners->on(Hyperf\Crontab\Event\CrontabDispatcherStarted::class, function () use ($c, $jobs) {
        $initialJobs = getenv('RECYCLE') ? 1 : $jobs;
        for ($i = 0; $i < $initialJobs; ++$i) {
            $cron = (new Crontab())->setName('worker-' . $i)->setCallback([WorkerCronJob::class, 'run', [(string)$i]])->setExecuteTime(Carbon\Carbon::now());
            $c->get(StrategyInterface::class)->dispatch($cron);
        }
        // Same worker round-robin sequence, already scheduled but not started when stop arrives.
        for ($i = 0; $i < $initialJobs; ++$i) {
            $future = (new Crontab())->setName('worker-future-' . $i)->setCallback([FutureWorkerCronJob::class, 'run'])->setExecuteTime(Carbon\Carbon::now()->addSeconds(getenv('STRATEGY') === 'process' ? 20 : 3));
            $c->get(StrategyInterface::class)->dispatch($future);
        }
        if (getenv('RECYCLE')) Swoole\Coroutine::create(function () use ($c) {
            do { $old = (int)@file_get_contents('/out/worker-0.pid'); if (!$old) Swoole\Coroutine::sleep(.01); } while (!$old);
            $deadline = microtime(true) + 30;
            do {
                $current = (int)@file_get_contents('/out/worker-0.pid');
                if ($current && $current !== $old) break;
                Swoole\Coroutine::sleep(.02);
            } while (microtime(true) < $deadline);
            verify($current && $current !== $old, 'max_request did not replace worker');
            record('replacement-dispatch', ['old_pid' => $old, 'new_pid' => $current]);
            $cron = (new Crontab())->setName('after-recycle')->setCallback([RecycledCronJob::class, 'run'])->setExecuteTime(Carbon\Carbon::now());
            $c->get(StrategyInterface::class)->dispatch($cron);
        });
    });
}
if (getenv('FAULT')) {
    $listeners->on(Hyperf\Crontab\Event\CrontabDispatcherStarted::class, function () {
        if (!file_exists('/out/fault-once')) { file_put_contents('/out/fault-once', '1'); throw new RuntimeException('expected-dispatcher-fault'); }
    });
}
if ($mode === 'cron' && !getenv('BOUNDARY_BATCH') && in_array(getenv('STRATEGY'), ['coroutine', 'custom'], true)) {
    $listeners->on(Hyperf\Crontab\Event\CrontabDispatcherStarted::class, function () use ($c, $seconds) {
        $cron = clone (new Crontab())->setName('in-process')->setType('closure')->setCallback(fn () => business('0', $seconds, true))->setExecuteTime(Carbon\Carbon::now());
        $c->get(Hyperf\Crontab\Strategy\Executor::class)->execute($cron);
    });
}
if ($mode === 'queue') $config->set('processes', [StopCheckConsumer::class]);
if ($mode === 'redis-shared') $config->set('processes', [SharedRedisConsumeProcess::class]);
if ($mode === 'shared') $config->set('processes', [SharedConsumeProcess::class]);
(new Hyperf\Process\Listener\BootProcessListener($c, $config))->process(new Hyperf\Framework\Event\BeforeMainServerStart($server, []));
$server->start();
record('server-exit');
