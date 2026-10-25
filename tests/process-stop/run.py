#!/usr/bin/env python3
"""Real process stop regressions; Docker/Python only, no production configuration."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid
import sys
sys.dont_write_bytecode = True
from types import SimpleNamespace
from lifecycle_cases import lifecycle_specs, run_lifecycle_case

SOURCE = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get('STOP_CHECK_IMAGE', 'registry.cn-hongkong.aliyuncs.com/dleno-server/php:alp324-php84-sw6.2.3')
ROOT = Path(tempfile.mkdtemp(prefix='common-core-stop-'))
PREFIX = 'cc-stop-' + uuid.uuid4().hex[:8]
NAMES = []


def docker(*args, check=True):
    result = subprocess.run(['docker', *map(str, args)], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and result.returncode:
        raise RuntimeError(result.stderr + result.stdout)
    return result.stdout.strip()


def events(directory, name='events.jsonl'):
    path = directory / name
    return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []


def wait_for(test, name, timeout=35):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if test():
            return
        time.sleep(.1)
    raise RuntimeError('Timed out waiting for ' + name)


def start(name, *args):
    full = PREFIX + '-' + name
    NAMES.append(full)
    docker('run', '-d', '--name', full, '--network', PREFIX, *args)
    return full


def redis_call(*args):
    return docker('exec', redis, 'redis-cli', '--raw', *args)


print('Artifacts: ' + str(ROOT), flush=True)
try:
    docker('network', 'create', PREFIX, '--internal')
    fixture = ROOT / 'fixture'
    fixture.mkdir()
    (fixture / 'composer.json').write_text((SOURCE / 'composer.json').read_text())
    build = PREFIX + '-build'
    NAMES.append(build)
    docker('run', '-d', '--name', build, '-v', f'{fixture}:/check', '-v', f'{SOURCE}:/source:ro', '-w', '/check', IMAGE, 'sleep', 'infinity')
    reuse = os.environ.get('STOP_CHECK_FIXTURE')
    if reuse:
        docker('cp', str(Path(reuse) / 'vendor'), build + ':/check/vendor')
        docker('cp', str(Path(reuse) / 'composer.lock'), build + ':/check/composer.lock')
    else:
        result = docker('exec', build, 'composer', 'update', '--no-interaction', '--no-progress', '--prefer-dist')
        (ROOT / 'composer-install.log').write_text(result)
    docker('exec', build, 'ln', '-s', '/source/src', '/check/src')
    (ROOT / 'heartbeat-config.log').write_text(docker('exec', build, 'php', '/source/tests/process-stop/heartbeat.php'))
    redis = start('redis', '--network-alias', 'redis', 'redis:7.4-alpine', 'redis-server', '--save', '', '--appendonly', 'no')
    wait_for(lambda: redis_call('ping') == 'PONG', 'Redis')
    rabbit = start('rabbit', '--network-alias', 'rabbit', '-e', 'RABBITMQ_DEFAULT_USER=audit', '-e', 'RABBITMQ_DEFAULT_PASS=isolated-test-only', 'rabbitmq:4-alpine')
    wait_for(lambda: 'Server startup complete' in docker('logs', rabbit, check=False), 'RabbitMQ', 60)
    http_out = ROOT / 'http'
    http_out.mkdir()
    start('http', '--network-alias', 'http', '-v', f'{SOURCE}:/source:ro', '-v', f'{http_out}:/out', IMAGE, 'php', '/source/tests/process-stop/http.php')
    time.sleep(1)
    cases = [
        dict(case='cron-worker-base', mode='cron', seconds=7, grace=30, worker_cron=1, resources=1, defer=1),
        dict(case='cron-worker-process', mode='cron', seconds=12, grace=35, worker_cron=1, resources=1, defer=1, server_mode='process'),
        dict(case='cron-task-worker', mode='cron', seconds=7, grace=35, worker_cron=1, resources=1, defer=1, strategy='task'),
        dict(case='cron-both-workers', mode='cron', seconds=7, grace=35, worker_cron=1, resources=1, defer=1, strategy='process', jobs=2, limit=2),
        dict(case='cron-worker-limit', mode='cron', seconds=12, grace=30, worker_cron=1, worker_wait=2, worker_limit=True),
        dict(case='cron-term', mode='cron', grace=10),
        dict(case='cron-int', mode='cron', grace=10, signal='SIGINT'),
        dict(case='cron-task-strategy', mode='cron', grace=10, strategy='task'),
        dict(case='cron-process-strategy', mode='cron', grace=10, strategy='process'),
        dict(case='cron-fault', mode='cron', grace=15, fault=1),
        dict(case='queue-idle', mode='queue', grace=10),
        # Stop three seconds into the seven-second request: about four seconds work + the unchanged five-second restart interval.
        dict(case='queue-seven', mode='queue', seconds=7, grace=10, stop_delay=3),
        dict(case='queue-seven-early', mode='queue', seconds=7, grace=30),
        dict(case='queue-twelve-grace', mode='queue', seconds=12, grace=30),
        dict(case='queue-twelve-limit', mode='queue', seconds=12, grace=10, killed=True),
        dict(case='queue-resources', mode='queue', seconds=7, grace=30, resources=1),
        dict(case='queue-parallel', mode='queue', seconds=7, grace=30, jobs=2, limit=2),
        dict(case='queue-reserved-slot', mode='queue', seconds=3, grace=30, jobs=2, limit=1),
        dict(case='queue-serial-defer', mode='queue', seconds=2, grace=15, defer=1, serial=1),
        dict(case='queue-defer', mode='queue', seconds=2, jobs=2, limit=2, grace=15, defer=1),
        dict(case='queue-retry', mode='queue', seconds=1, grace=15, retry=1),
        dict(case='queue-max-messages', mode='queue', seconds=1, grace=15, jobs=2, limit=2, max_messages=1),
        dict(case='queue-pending-start', mode='queue', seconds=1, grace=15, jobs=2, limit=2, max_messages=1, defer=1, pending_start=1),
        dict(case='queue-shared-drain', mode='redis-shared', seconds=4, grace=20, jobs=4, limit=4, max_messages=1, defer=1),
        dict(case='queue-nested-unjoined', mode='queue', seconds=1, grace=15, jobs=2, limit=2, nested='unjoined'),
        dict(case='queue-nested-barrier', mode='queue', seconds=1, grace=15, jobs=2, limit=2, nested='barrier'),
        dict(case='amqp-idle', mode='amqp', grace=15),
        dict(case='amqp-serial', mode='amqp', seconds=7, grace=20),
        dict(case='amqp-serial-defer', mode='amqp', seconds=2, grace=15, defer=1),
        dict(case='amqp-defer', mode='amqp', seconds=2, jobs=2, limit=2, grace=15, defer=1),
        dict(case='amqp-shared', mode='shared', seconds=7, jobs=2, limit=2, grace=20, defer=1),
        dict(case='amqp-parallel', mode='amqp', seconds=12, grace=30, jobs=2, limit=2, resources=1),
        dict(case='amqp-default-idle', mode='amqp', grace=15, heartbeat='omit', check_connection=1, minimal_params=1, custom_io=1),
        dict(case='amqp-default-int', mode='amqp', grace=15, heartbeat='omit', check_connection=1, signal='SIGINT'),
        dict(case='amqp-default-defer', mode='amqp', seconds=2, grace=15, heartbeat='omit', check_connection=1, defer=1),
        dict(case='amqp-default-shared', mode='shared', seconds=7, jobs=2, limit=2, grace=25, heartbeat='omit', check_connection=1, defer=1),
        dict(case='amqp-default-parallel', mode='amqp', seconds=12, jobs=2, limit=2, grace=30, heartbeat='omit', check_connection=1, resources=1, connections=2, custom_io=1),
        dict(case='amqp-worker-heartbeat', mode='publisher', grace=15, heartbeat='omit', check_connection=1),
        dict(case='amqp-default-setup-fault', mode='amqp', grace=15, heartbeat='omit', check_connection=1, fault=1, early_fault=1, expected_error='expected-process-setup-fault'),
        dict(case='amqp-default-max', mode='amqp', seconds=1, grace=15, heartbeat='omit', check_connection=1, max_consumption=1, defer=1),
        dict(case='amqp-positive-heartbeat', mode='amqp', seconds=2, grace=15, heartbeat=7, check_connection=1, custom_io=1, defer=1),
        dict(case='cron-boundary', mode='cron', grace=10, boundary=1),
        dict(case='cron-coroutine-boundary', mode='cron', seconds=20, grace=45, strategy='coroutine', resources=1),
        dict(case='cron-locked-batch', mode='cron', seconds=8, grace=40, strategy='coroutine', resources=1, boundary_batch=1),
        dict(case='cron-custom-boundary', mode='cron', seconds=20, grace=45, strategy='custom', resources=1),
    ]
    cases += lifecycle_specs()
    lifecycle_context = SimpleNamespace(docker=docker, events=events, wait_for=wait_for, redis_call=redis_call, prefix=PREFIX, rabbit=rabbit, http_out=http_out)
    selected = os.environ.get('STOP_CHECK_CASES')
    if selected:
        cases = [c for c in cases if c['case'] in selected.split(',')]
        assert cases, 'No matching cases'
    results = []
    for spec in cases:
        case, mode, grace = (spec[k] for k in ['case', 'mode', 'grace'])
        seconds = spec.get('seconds', 0)
        jobs = spec.get('jobs', 1 if seconds else 0)
        signal = spec.get('signal', 'SIGTERM')
        if mode == 'cron' and not spec.get('lifecycle'):
            if spec.get('strategy') in ['coroutine', 'custom'] or spec.get('boundary'):
                wait_for(lambda: 44 < time.time() % 60 < 46, 'about 15s before next minute', 65)
            else:
                wait_for(lambda: time.time() % 60 < 35, 'more than 25s before next minute', 65)
        out = ROOT / case
        out.mkdir()
        http_before = len(events(http_out, 'http.jsonl'))
        env = dict(CASE=case, MODE=mode, SECONDS=seconds, JOBS=jobs, LIMIT=spec.get('limit', 1), RESOURCES=spec.get('resources', ''), RETRY=spec.get('retry', ''), MAX_MESSAGES=spec.get('max_messages', 0), STRATEGY=spec.get('strategy', ''), FAULT=spec.get('fault', ''), BOUNDARY=spec.get('boundary', ''), UNSAFE_STOP=os.environ.get('STOP_CHECK_UNSAFE', ''), DEFER=spec.get('defer', ''), BOUNDARY_BATCH=spec.get('boundary_batch', ''), SERIAL=spec.get('serial', ''), PENDING_START=spec.get('pending_start', ''), NESTED=spec.get('nested', ''), HEARTBEAT=spec.get('heartbeat', 10), CONNECTIONS=spec.get('connections', 1), CUSTOM_IO=spec.get('custom_io', ''), CHECK_CONNECTION=spec.get('check_connection', ''), MAX_CONSUMPTION=spec.get('max_consumption', 0), EARLY_FAULT=spec.get('early_fault', ''), MINIMAL_PARAMS=spec.get('minimal_params', ''), WORKER_CRON=spec.get('worker_cron', ''), SERVER_MODE=spec.get('server_mode', ''), WORKER_WAIT=spec.get('worker_wait', 30), REPEAT_SIGNAL_OBSERVER=int(spec.get('lifecycle') == 'repeat' and not spec.get('worker_cron')), RECYCLE=int(spec.get('lifecycle') == 'recycle'), BROKER_OUTAGE=spec.get('outage', ''))
        args = []
        for key, value in env.items():
            args += ['-e', f'{key}={value}']
        name = start(case, '--no-healthcheck', '--stop-timeout', grace, *args, '-v', f'{fixture}:/check:ro', '-v', f'{SOURCE}:/source:ro', '-v', f'{out}:/out', '-w', '/check', IMAGE, 'php', '/source/tests/process-stop/runtime.php')
        try:
            if spec.get('lifecycle'):
                result = run_lifecycle_case(spec, name, out, http_before, lifecycle_context)
                results.append(result)
                print(json.dumps(result), flush=True)
                (ROOT / 'results.json').write_text(json.dumps(results, indent=2))
                continue
            wait_for(lambda: any(e['event'] == ('worker-ready' if mode == 'publisher' else 'process-ready') for e in events(out)), case + ' ready')
            if spec.get('nested'):
                wait_for(lambda: sum(e['event'] == 'nested-defer-start' for e in events(out)) == jobs, 'nested defer started')
            elif seconds and not spec.get('boundary_batch'):
                starts = jobs if spec.get('limit', 1) > 1 else 1
                wait_for(lambda: sum(e['event'] == 'job-start' for e in events(out)) >= starts and len(events(http_out, 'http.jsonl')) >= http_before + starts, 'slow HTTP started')
            else:
                time.sleep(.3)
            if spec.get('boundary'):
                wait_for(lambda: any(e['event'] == 'minute-task' for e in events(out)), 'actual minute dispatch', 25)
            if spec.get('fault'):
                wait_for(lambda: any(e['event'] == 'process-exit' for e in events(out)), 'watcher exits after handle exception', 10)
            if spec.get('stop_delay'):

                time.sleep(spec['stop_delay'])
            if case == 'queue-reserved-slot':
                wait_for(lambda: redis_call('zcard', '{' + case + '}:reserved') == '2', 'both messages reserved')
            if (spec.get('max_messages') and mode != 'redis-shared') or spec.get('max_consumption'):
                wait_for(lambda: any(e['event'] == 'handle-returned' for e in events(out)), 'max_messages drain')
            start_time = time.monotonic()
            if signal == 'SIGINT':
                pid = next(e['pid'] for e in events(out) if e['event'] == 'process-ready')
                docker('exec', name, 'kill', '-INT', pid)
                wait_for(lambda: any(e['event'] == 'handle-returned' for e in events(out)), 'SIGINT handling', 3)
            stop = subprocess.Popen(['docker', 'stop', '--timeout', str(grace), name], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            resources_checked = False
            while stop.poll() is None:
                data = events(out)
                if spec.get('resources') and not resources_checked and any(e['event'] == 'resources-acquired' and time.time() - e['time'] > 5.5 for e in data) and not any(e['event'] == 'job-done' for e in data):
                    stem = hashlib.sha1((case + '-0* * * * *').encode()).hexdigest()
                    for key in ['{audit-' + case + '-0}', 'framework/crontab-' + stem, 'hyperf/crontab-' + stem + '-sv']:
                        assert redis_call('set', key, 'competitor', 'NX', 'EX', 2) == '', 'Lock lost while business active: ' + key
                    resources_checked = True
                    resource_probe_time = time.time()
                time.sleep(.1)
            stdout, stderr = stop.communicate()
            assert stop.returncode == 0, stderr.decode()
            elapsed = time.monotonic() - start_time
            state = json.loads(docker('inspect', name))[0]['State']
            data = events(out)
            names = [e['event'] for e in data]
            reserved = int(redis_call('zcard', '{' + case + '}:reserved'))
            http_calls = events(http_out, 'http.jsonl')[http_before:]
            killed = spec.get('killed', False)
            assert state['ExitCode'] == (137 if killed else 0), state
            assert reserved == (1 if killed else 0), (reserved, data)
            assert 'job-error' not in names, data
            errors = [e for e in data if e['event'] == 'log' and e['level'] in ['error', 'critical']]
            if spec.get('fault'):
                assert errors and all(spec.get('expected_error', 'expected-dispatcher-fault') in e['message'] for e in errors), data
            else:
                unexpected = [e for e in errors if not ((spec.get('boundary_batch') and 'Crontab task [future-probe] failed execution' in e['message']) or (spec.get('worker_cron') and 'Crontab task [worker-future-' in e['message']))]
                assert not unexpected, data
            if not killed and mode != 'publisher' and not spec.get('worker_limit'):
                assert 'process-exit' in names and 'handle-returned' in names, data
                if spec.get('fault'):
                    first_ready = next(e for e in data if e['event'] == 'process-ready')
                    first_exit = next(e for e in data if e['event'] == 'process-exit')
                    assert first_exit['time'] - first_ready['time'] < 10 and names.count('process-ready') <= 2, data
                else:
                    assert names.count('process-ready') == 1, data
                assert elapsed < grace, elapsed
                if case == 'queue-idle': assert elapsed < 8.5, elapsed
                if mode == 'cron' and not seconds and not spec.get('fault'): assert elapsed < 7.5, elapsed
                if case == 'amqp-idle': assert elapsed < 11.5, elapsed
                pools = next(e.get('pools', {}) for e in data if e['event'] == 'process-exit')
                assert all(not p['maintenanceRunning'] for p in (pools or {}).values()), pools
                exit_time = next(e['time'] for e in data if e['event'] == 'exit-coordinator')
                finish_events = ['job-done', 'acked', 'retried', 'amqp-business-returned', 'task-defer-done', 'boundary-locks-retained', 'locks-unlocked']
                for e in data:
                    if e['event'] in finish_events:
                        cutoff = next(x['time'] for x in data if x['event'] == 'worker-coordinator' and x['pid'] == e['pid']) if spec.get('worker_cron') else exit_time
                        assert e['time'] <= cutoff, data
            if seconds and mode != 'publisher' and not spec.get('worker_limit'):
                assert names.count('job-start') == jobs, data
                running_false = next((e['time'] for e in data if e['event'] == 'running-false'), 0) if not (spec.get('max_messages') or spec.get('max_consumption') or spec.get('worker_cron')) else None
                if running_false is not None:
                    first_start = min(e['time'] for e in data if e['event'] == 'job-start')
                    assert (running_false < first_start if spec.get('boundary_batch') else first_start < running_false), data
                if not killed and running_false is not None:
                    assert running_false < min(e['time'] for e in data if e['event'] == 'job-done'), data
                if killed:
                    assert names.count('acked') == 0 and len(http_calls) == 1, data
                else:
                    assert names.count('job-done') == jobs and names.count('http-ok') == jobs * 2 and len(http_calls) == jobs * (3 if spec.get('defer') else 2), data
                    if mode in ['queue', 'redis-shared']:
                        assert names.count('acked') == (0 if spec.get('retry') else jobs), data
                    if spec.get('defer'):
                        assert names.count('task-defer-done') == jobs, data
                    if spec.get('retry'):

                        assert names.count('retried') == jobs and redis_call('zcard', '{' + case + '}:delayed') == str(jobs), data
                    if spec.get('resources'):
                        assert resources_checked and names.count('resources-retained') == jobs and names.count('locks-unlocked') == jobs, data
                        assert resource_probe_time < min(e['time'] for e in data if e['event'] == 'job-done'), data
            if spec.get('worker_limit'):
                assert names.count('job-start') == 1 and names.count('job-done') == 0, data
                assert len(http_calls) == 1 and 'task-defer-done' not in names, data
                native = next(e for e in data if e['event'] == 'native-worker-exit' and e['running'] > 0)
                assert not any(e['event'] == 'parent-worker-exit' and e['pid'] == native['pid'] for e in data), data
                assert 'worker exit timeout, forced termination' in (out / 'swoole.log').read_text(), data
            if spec.get('worker_cron') and not spec.get('worker_limit'):
                assert 'future-worker-task' not in names, data
                started_pids = {e['pid'] for e in data if e['event'] == 'job-start'}
                assert len(started_pids) == jobs, data
                for pid in started_pids:
                    local = [e for e in data if e['pid'] == pid]
                    native = next(e for e in local if e['event'] == 'native-worker-exit')
                    parent = [e for e in local if e['event'] == 'parent-worker-exit']
                    assert native['running'] > 0 and native['cid'] == -1, local
                    assert parent and all(e['running'] == 0 and e['cid'] == -1 for e in parent), local
                    assert native['time'] < next(e['time'] for e in local if e['event'] == 'job-done'), local
                    assert parent[0]['time'] >= max(e['time'] for e in local if e['event'] in ['task-defer-done', 'locks-unlocked']), local
            if spec.get('check_connection'):
                configured = next(e for e in data if e['event'] == 'connection-config')
                expected = 15 if spec.get('heartbeat') == 'omit' else spec['heartbeat']
                assert configured['heartbeat'] == expected, configured
            if spec.get('custom_io'):
                used_io = [e for e in data if e['event'] == 'custom-io']
                assert len(used_io) == spec.get('connections', 1) and all(e['heartbeat'] == (15 if spec.get('heartbeat') == 'omit' else spec['heartbeat']) and e['read_write_timeout'] == (30 if spec.get('minimal_params') else 25) and e['connection_timeout'] == (3 if spec.get('minimal_params') else 5) for e in used_io), data
            assert names.count('factory-inheritance-ok') == 1, data
            if spec.get('pending_start'):
                assert names.count('callback-pending') == jobs, data
            if mode == 'redis-shared':
                fast_return = next(e['time'] for e in data if e['event'] == 'shared-consumer-returned' and e['name'] == 'fast')
                slow_done = min(e['time'] for e in data if e['event'] == 'job-done' and e['id'].startswith('slow-'))
                fast_defer = max(e['time'] for e in data if e['event'] == 'task-defer-done' and e['id'].startswith('fast-'))
                assert fast_defer <= fast_return < slow_done, 'Shared consume drain coupled to unrelated owner or returned before own defer'
            if spec.get('nested'):
                nested = [e for e in data if e['event'] == 'nested-defer-done']
                assert len(nested) == jobs, data
                joined = spec['nested'] == 'barrier'
                assert all(e['closing'] is not joined for e in nested), data
                assert names.count('nested-parallel-returned') == jobs, data
                if joined:
                    assert names.count('nested-barrier-returned') == jobs and all(e['time'] < exit_time for e in nested), data
            if mode == 'publisher':
                assert names.count('worker-publish-confirmed') == 1 and names.count('worker-heartbeat-closed') == 1, data
                assert names.count('job-start') == 0 and len(http_calls) == 0, data
                assert 'worker-process-exit' in names and elapsed < grace, data
                exit_time = next(e['time'] for e in data if e['event'] == 'exit-coordinator')
                assert next(e['time'] for e in data if e['event'] == 'worker-publish-confirmed') < exit_time, data
                queues = docker('exec', rabbit, 'rabbitmqctl', '-q', 'list_queues', 'name', 'messages_ready', 'messages_unacknowledged')
                assert 'publish-' + case + '\t1\t0' in queues, queues
                (out / 'broker-queues.txt').write_text(queues)
            if mode == 'cron':
                ready = next(e for e in data if e['event'] == 'process-ready')
                assert ready['to_next_minute'] > 10, ready
                if spec.get('boundary'):
                    dispatched = [e for e in data if e['event'] == 'minute-task']
                    assert len(dispatched) == 1 and dispatched[0]['second'] < 2, data
                if spec.get('strategy') in ['coroutine', 'custom']:
                    assert elapsed > 10, 'Custom/in-process strategy incorrectly used the fast-exit watcher'
            if spec.get('boundary_batch'):
                assert names.count('boundary-locks-retained') == 1 and 'future-task' not in names, data
                assert any('Crontab task [future-probe] failed execution' in e['message'] for e in errors), data
            if mode in ['amqp', 'shared']:
                deliveries = [e for e in data if e['event'] == 'amqp-delivery']
                assert len(deliveries) == jobs and not any(e['redelivered'] for e in deliveries), data
                assert len({e['tag'] for e in deliveries}) == jobs if mode != 'shared' else True, data
                # Broker readback, rather than an AfterConsume event, proves the ACK crossed the wire.
                queues = docker('exec', rabbit, 'rabbitmqctl', '-q', 'list_queues', 'name', 'messages_ready', 'messages_unacknowledged')
                (out / 'broker-queues.txt').write_text(queues)
                for queue_name in ([case + '-fast', case + '-slow'] if mode == 'shared' else [case]):
                    row = next(line.split() for line in queues.splitlines() if line.startswith(queue_name + '\t'))
                    assert row[1:] == ['0', '0'], queues
                if mode == 'shared':
                    fast_return = next(e['time'] for e in data if e['event'] == 'shared-consumer-returned' and e['name'] == 'fast')
                    slow_done = next(e['time'] for e in data if e['event'] == 'job-done' and e['id'] == 'slow')
                    assert fast_return < slow_done, 'One consumer was held by another consumer callback'
            if spec.get('resources'):
                queues = docker('exec', rabbit, 'rabbitmqctl', '-q', 'list_queues', 'name', 'messages_ready', 'messages_unacknowledged')
                assert 'publish-' + case + '\t' + str(jobs) + '\t0' in queues, queues
                (out / 'broker-queues.txt').write_text(queues)
            result = dict(case=case, signal=signal, grace=grace, stop_delay=spec.get('stop_delay', 0), stop_seconds=round(elapsed, 3), exit_code=state['ExitCode'], reserved=reserved, jobs=jobs, http_requests=len(http_calls), expected_hard_limit=killed, expected_worker_limit=spec.get('worker_limit', False))
            results.append(result)
            print(json.dumps(result), flush=True)
            (ROOT / 'results.json').write_text(json.dumps(results, indent=2))
        finally:
            (out / 'container.log').write_text(docker('logs', name, check=False))
            if mode in ['amqp', 'shared', 'publisher'] or spec.get('resources'):
                (out / 'broker-queues.txt').write_text(docker('exec', rabbit, 'rabbitmqctl', '-q', 'list_queues', 'name', 'messages_ready', 'messages_unacknowledged', check=False))
    print('PASS: assertions matched; boundary cases are not normal graceful exits.', flush=True)
finally:
    for name in reversed(NAMES):
        docker('rm', '-f', name, check=False)
    docker('network', 'rm', PREFIX, check=False)
