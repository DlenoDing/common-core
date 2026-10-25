<?php

declare(strict_types=1);

// Configuration checks stop inside the real parent IO factory, before any network operation.
$loader = require '/check/vendor/autoload.php';
$loader->addPsr4('Dleno\\CommonCore\\', '/source/src', true);

class HeartbeatIoIntercepted extends RuntimeException {}
class InheritedHeartbeatFactory extends Dleno\CommonCore\Base\Amqp\ConnectionFactory {}

$container = new Hyperf\Di\Container(new Hyperf\Di\Definition\DefinitionSource([]));
$container->set(Hyperf\Contract\ConfigInterface::class, new Hyperf\Config\Config([]));
$checked = 0;
foreach ([Dleno\CommonCore\Base\Amqp\ConnectionFactory::class, InheritedHeartbeatFactory::class] as $class) {
    $factory = new $class($container);
    foreach ([[], ['heartbeat' => null], ['heartbeat' => 1], ['heartbeat' => 3], ['heartbeat' => 7], ['heartbeat' => 30]] as $values) {
        $expected = $values['heartbeat'] ?? 15;
        $called = false;
        $config = ['params' => $values + ['read_write_timeout' => 25, 'connection_timeout' => 5, 'keepalive' => true], 'pool' => ['connections' => 2], 'host' => 'must-not-connect.invalid'];
        $config['io'] = function (array $actual, Hyperf\Amqp\Params $params) use (&$called, $expected): never {
            $called = true;
            if ($params->getHeartbeat() !== $expected || $actual['params']['heartbeat'] !== $expected
                || $params->getReadWriteTimeout() !== 25 || $params->getConnectionTimeout() !== 5
                || $actual['pool']['connections'] !== 2) {
                throw new RuntimeException('Heartbeat default or parameter forwarding changed');
            }
            throw new HeartbeatIoIntercepted();
        };
        $before = $config;
        try {
            $factory->make($config);
            throw new RuntimeException('IO interception was bypassed');
        } catch (HeartbeatIoIntercepted) {
            if (!$called || $config !== $before) throw new RuntimeException('Caller config mutated or IO not called');
        }
        ++$checked;
    }
    foreach ([0, -1, -100, '0', '3', '', true, false, 3.5, []] as $invalid) {
        $called = false;
        try {
            $factory->make(['params' => ['heartbeat' => $invalid], 'io' => function () use (&$called): never {
                $called = true;
                throw new RuntimeException('Invalid heartbeat reached IO');
            }]);
            throw new RuntimeException('Invalid heartbeat accepted');
        } catch (InvalidArgumentException $error) {
            if ($called || !str_contains($error->getMessage(), 'heartbeat')) throw new RuntimeException('Heartbeat error missing or too late');
        }
        ++$checked;
    }
    foreach ([[[], 15, 30], [['params' => null], 15, 30], [['params' => ['heartbeat' => 7]], 7, 30], [['params' => ['read_write_timeout' => 0]], 15, 0]] as [$minimal, $expectedHeartbeat, $expectedTimeout]) {
        $minimal['io'] = function (array $actual, Hyperf\Amqp\Params $params) use ($expectedHeartbeat, $expectedTimeout): never {
            if ($params->getHeartbeat() !== $expectedHeartbeat || $params->getReadWriteTimeout() !== $expectedTimeout) throw new RuntimeException('Minimal config defaults changed');
            throw new HeartbeatIoIntercepted();
        };
        try {
            $factory->make($minimal);
            throw new RuntimeException('Minimal config IO interception was bypassed');
        } catch (HeartbeatIoIntercepted) {
        }
        ++$checked;
    }
}
echo "PASS: {$checked} heartbeat default/validation/forwarding/inheritance checks; no network IO.\n";
