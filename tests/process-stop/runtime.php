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
    file_put_contents('/out/events.jsonl', json_encode(['event' => $event, 'time' => microtime(true), 'pid' => getmypid()] + $data) . "\n", FILE_APPEND | LOCK_EX);
}
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
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
class ApplicationDispatcher extends CrontabDispatcherProcess {}
class CustomStrategy extends WorkerStrategy {}
class BoundServer extends Hyperf\Server\Server { public function attach(Swoole\Server $server): void { $this->server = $server; } }
class MinuteProbe { public function run(): void { record('minute-task', ['second' => (int)date('s')]); } }
class AppRedisDriver extends Hyperf\AsyncQueue\Driver\RedisDriver {}
class AppAmqpConsumer extends Hyperf\Amqp\Consumer { public array $factoryParameters = []; }
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
    public function __construct() { $this->qos = ['prefetch_size' => 0, 'prefetch_count' => (int)(getenv('LIMIT') ?: 1), 'global' => false]; }
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
        business($this->id, $this->seconds, $this->resources);
        if ($this->retry) throw new RuntimeException('expected-retry');
    }
}
function business(string $id, int $seconds, bool $resources): void
{
    record('job-start', ['id' => $id]);
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
        $channel->wait_for_pending_acks_returns(5);
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
    'amqp' => ['default' => ['host' => 'rabbit', 'user' => 'audit', 'password' => 'isolated-test-only', 'pool' => ['connections' => 1],
        'concurrent' => ['limit' => $limit], 'params' => ['heartbeat' => 10, 'read_write_timeout' => 25, 'connection_timeout' => 5]]],
]);
if (getenv('SERIAL')) $config->set('async_queue.default.concurrent', []);
$c->set(ConfigInterface::class, $config);
$listeners = new Hyperf\Event\ListenerProvider();
$c->set(EventDispatcherInterface::class, new Hyperf\Event\EventDispatcher($listeners));
foreach ([Hyperf\Signal\Listener\SignalRegisterListener::class, Hyperf\Signal\Listener\SignalDeregisterListener::class] as $class) {
    $listener = $c->get($class);
    foreach ($listener->listen() as $event) $listeners->on($event, [$listener, 'process'], $class === Hyperf\Signal\Listener\SignalRegisterListener::class ? PHP_INT_MAX : 0);
}
$listeners->on(BeforeProcessHandle::class, function ($event) use ($c) {
    $handlers = $c->get(Hyperf\Signal\SignalManager::class)->getHandlers();
    foreach ([SIGTERM, SIGINT] as $signal) verify(count($handlers[2][$signal]) === 1, 'Duplicate signal handler');
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
    register_shutdown_function(fn () => record('process-exit', ['pools' => ElasticHttpPool::statistics()]));
    record('process-ready', ['class' => get_class($event->process), 'to_next_minute' => 60 - fmod(microtime(true), 60)]);
});
$listeners->on(AfterProcessHandle::class, fn () => record('handle-returned'));
$listeners->on(Hyperf\AsyncQueue\Event\AfterHandle::class, fn () => record('acked'));
$listeners->on(Hyperf\AsyncQueue\Event\RetryHandle::class, fn () => record('retried'));
$listeners->on(Hyperf\AsyncQueue\Event\FailedHandle::class, fn () => record('job-error'));
$listeners->on(Hyperf\Amqp\Event\AfterConsume::class, fn () => record('amqp-business-returned'));
$listeners->on(Hyperf\Amqp\Event\FailToConsume::class, fn () => record('job-error'));
// Class mapping and application overrides, using the same array_replace precedence as DefinitionSourceFactory.
foreach ([CrontabDispatcherProcess::class => Dleno\CommonCore\Process\CrontabDispatcherProcess::class,
    Hyperf\Amqp\Consumer::class => Dleno\CommonCore\Base\Amqp\Consumer::class,
    Hyperf\Crontab\Strategy\Executor::class => Dleno\CommonCore\Process\CrontabExecutor::class] as $key => $class) {
    verify(get_class($c->get($key)) === $class, 'Default binding lost: ' . $key);
    verify(str_starts_with((new ReflectionClass($class))->getFileName(), '/source/'), 'Loaded installed package instead of checkout');
}
verify(get_class($c->get(BaseDriverFactory::class)->get('default')) === Dleno\CommonCore\Base\AsyncQueue\RedisDriver::class, 'Queue driver not drained');
$appDefs = array_replace($defs, [CrontabDispatcherProcess::class => ApplicationDispatcher::class,
    Hyperf\AsyncQueue\Driver\RedisDriver::class => AppRedisDriver::class, Hyperf\Crontab\Strategy\Executor::class => AppExecutor::class,
    Hyperf\Amqp\Consumer::class => fn ($container) => new AppAmqpConsumer($container, $container->get(Hyperf\Amqp\ConnectionFactory::class), new CheckLogger())]);
$app = new Container(new DefinitionSource($appDefs));
$app->set(ConfigInterface::class, $config);
$app->set(EventDispatcherInterface::class, $c->get(EventDispatcherInterface::class));
foreach ([CrontabDispatcherProcess::class => ApplicationDispatcher::class, Hyperf\Amqp\Consumer::class => AppAmqpConsumer::class, Hyperf\Crontab\Strategy\Executor::class => AppExecutor::class] as $key => $class) verify(get_class($app->get($key)) === $class, 'Application override lost');
$factoryApp = new Container(new DefinitionSource(array_replace($defs, [Hyperf\Amqp\ConsumerFactory::class => fn () => new AppAmqpFactory()])));
$factoryApp->set(ConfigInterface::class, $config);
$factoryApp->set(EventDispatcherInterface::class, $c->get(EventDispatcherInterface::class));
verify(get_class($factoryApp->get(Hyperf\Amqp\Consumer::class)) === AppAmqpConsumer::class, 'Existing app ConsumerFactory override lost');
verify($factoryApp->make(Hyperf\Amqp\Consumer::class, ['probe' => 'retained'])->factoryParameters === ['probe' => 'retained'], 'Factory parameters lost');
verify(get_class($app->make(Hyperf\AsyncQueue\Driver\RedisDriver::class, ['config' => $config->get('async_queue.default')])) === AppRedisDriver::class, 'App Redis driver override lost');
verify($c->get(CrontabDispatcherProcess::class)->getInterval(5, .25) === 54.75, 'Minute interval changed');
foreach ([StopCheckConsumer::class, Dleno\CommonCore\Process\CrontabDispatcherProcess::class,
    Dleno\CommonCore\Websocket\Process\DcsMessageConsumer::class, Dleno\CommonCore\Websocket\Process\DcsControlConsumer::class] as $class) {
    verify((new ReflectionClass($class))->getDefaultProperties()['recvTimeout'] === 1.0, 'Pipe timeout not inherited');
}
record('registration-ok', ['php' => PHP_VERSION, 'swoole' => swoole_version()]);
$seconds = (int)getenv('SECONDS');
$jobs = (int)(getenv('JOBS') ?: ($seconds > 0 ? 1 : 0));
if ($mode === 'queue' && $jobs) {
    Swoole\Coroutine\run(function () use ($c, $jobs, $seconds) {
        $driver = $c->get(BaseDriverFactory::class)->get('default');
        for ($i = 0; $i < $jobs; ++$i) {
            $job = new HttpJob((string)$i, $seconds, (bool)getenv('RESOURCES'), (bool)getenv('RETRY'));
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
$server = new Swoole\Http\Server('127.0.0.1', 9501, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'max_wait_time' => 30, 'log_file' => '/out/swoole.log']);
$server->on('request', fn ($request, $response) => $response->end('ok'));
$server->on('pipeMessage', function ($server, $from, $data) use ($c) {
    $c->get(Hyperf\Crontab\Listener\OnPipeMessageListener::class)->process(new Hyperf\Framework\Event\OnPipeMessage($server, $from, $data));
});
$server->on('workerExit', fn () => Hyperf\Coordinator\CoordinatorManager::until(Hyperf\Coordinator\Constants::WORKER_EXIT)->resume());
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
if ($mode === 'shared') $config->set('processes', [SharedConsumeProcess::class]);
(new Hyperf\Process\Listener\BootProcessListener($c, $config))->process(new Hyperf\Framework\Event\BeforeMainServerStart($server, []));
$server->start();
record('server-exit');
