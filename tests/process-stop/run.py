#!/usr/bin/env python3
"""Run only isolated fixtures. Requires Docker, Python 3, and network for Composer."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

SOURCE = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get('STOP_CHECK_IMAGE', 'registry.cn-hongkong.aliyuncs.com/dleno-server/php:alp324-php84-sw6.2.3')
ROOT = Path(tempfile.mkdtemp(prefix='common-core-stop-'))
PREFIX = 'cc-stop-' + uuid.uuid4().hex[:8]
NAMES = []


def docker(*args, check=True):
    return subprocess.run(['docker', *map(str, args)], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=check).stdout.strip()


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


print('Artifacts: ' + str(ROOT), flush=True)
try:
    docker('network', 'create', PREFIX, '--internal')
    # A separate build container can access Composer; runtime network has no external route.
    fixture = ROOT / 'fixture'
    fixture.mkdir()
    (fixture / 'composer.json').write_text((SOURCE / 'composer.json').read_text())
    build = PREFIX + '-build'
    NAMES.append(build)
    docker('run', '-d', '--name', build, '-v', f'{fixture}:/check', '-v', f'{SOURCE}:/source:ro', '-w', '/check', IMAGE, 'sleep', 'infinity')
    # Optional reuse of an external dependency fixture saves downloads, never a consumer vendor.
    reuse = os.environ.get('STOP_CHECK_FIXTURE')
    if reuse:
        docker('cp', str(Path(reuse) / 'vendor'), build + ':/check/vendor')
        docker('cp', str(Path(reuse) / 'composer.lock'), build + ':/check/composer.lock')
    else:
        result = docker('exec', build, 'composer', 'update', '--no-interaction', '--no-progress', '--prefer-dist')
        (ROOT / 'composer-install.log').write_text(result)
    docker('exec', build, 'ln', '-s', '/source/src', '/check/src')
    redis = start('redis', '--network-alias', 'redis', 'redis:7.4-alpine', 'redis-server', '--save', '', '--appendonly', 'no')
    wait_for(lambda: docker('exec', redis, 'redis-cli', 'ping', check=False) == 'PONG', 'Redis')
    http_out = ROOT / 'http'
    http_out.mkdir()
    start('http', '--network-alias', 'http', '-v', f'{SOURCE}:/source:ro', '-v', f'{http_out}:/out', IMAGE, 'php', '/source/tests/process-stop/http.php')
    time.sleep(1)
    results = []
    for case, mode, seconds, grace, signal in [
        ('cron-term', 'cron', 0, 10, 'SIGTERM'),
        ('cron-int', 'cron', 0, 10, 'SIGINT'),
        ('queue-idle', 'queue', 0, 10, 'SIGTERM'),
        ('queue-seven', 'queue', 7, 10, 'SIGTERM'),
        ('queue-twelve-grace', 'queue', 12, 30, 'SIGTERM'),
        ('queue-twelve-limit', 'queue', 12, 10, 'SIGTERM'),
    ]:
        if mode == 'cron':
            wait_for(lambda: time.time() % 60 < 35, 'more than 25s before next minute', 65)
        out = ROOT / case
        out.mkdir()
        http_before = len(events(http_out, 'http.jsonl'))
        name = start(case, '--stop-timeout', grace,
            '-e', f'CASE={case}', '-e', f'MODE={mode}', '-e', f'SECONDS={seconds}',
            '-v', f'{fixture}:/check:ro', '-v', f'{SOURCE}:/source:ro', '-v', f'{out}:/out', '-w', '/check',
            IMAGE, 'php', '/source/tests/process-stop/runtime.php')
        try:
            wait_for(lambda: any(e['event'] == 'process-ready' for e in events(out)), case + ' ready')
            if seconds:
                wait_for(lambda: len(events(http_out, 'http.jsonl')) > http_before, 'slow HTTP started')
            else:
                time.sleep(.3)
            start_time = time.monotonic()
            if signal == 'SIGINT':
                pid = next(e['pid'] for e in events(out) if e['event'] == 'process-ready')
                docker('exec', name, 'kill', '-INT', pid)
                wait_for(lambda: any(e['event'] == 'handle-returned' for e in events(out)), 'SIGINT handling', 2)
            docker('stop', '--timeout', grace, name)
            elapsed = time.monotonic() - start_time
            state = json.loads(docker('inspect', name))[0]['State']
            data = events(out)
            reserved = int(docker('exec', redis, 'redis-cli', 'zcard', '{' + case + '}:reserved'))
            http_calls = events(http_out, 'http.jsonl')[http_before:]
            names = [e['event'] for e in data]
            killed = case == 'queue-twelve-limit'
            assert state['ExitCode'] == (137 if killed else 0), state
            assert reserved == (1 if killed else 0), (reserved, data)
            assert 'job-error' not in names and not [e for e in data if e['event'] == 'log' and e['level'] in ['error', 'critical']], data
            if not killed:
                assert 'process-exit' in names and 'handle-returned' in names, data
                assert any(e['event'] == 'exit-coordinator' and e['running'] is False for e in data), data
                assert elapsed < grace, elapsed
            if seconds:
                assert names.count('job-start') == 1, data
                assert names.count('acked') == (0 if killed else 1), data
                assert [e['path'] for e in http_calls] == (['/slow'] if killed else ['/slow', '/second']), http_calls
                if not killed:
                    assert names.count('job-done') == 1 and names.count('http-ok') == 2, data
                    pools = next(e['pools'] for e in data if e['event'] == 'job-done')
                    assert all(not p['maintenanceRunning'] for p in pools.values()), pools
            if mode == 'cron':
                ready = next(e for e in data if e['event'] == 'process-ready')
                assert ready['to_next_minute'] > 10, ready
                assert 'dispatch' not in names, data
            result = dict(case=case, signal=signal, grace=grace, stop_seconds=round(elapsed, 3), exit_code=state['ExitCode'], reserved=reserved, http_requests=len(http_calls), expected_hard_limit=killed)
            results.append(result)
            print(json.dumps(result), flush=True)
        finally:
            (out / 'container.log').write_text(docker('logs', name, check=False))
    (ROOT / 'results.json').write_text(json.dumps(results, indent=2))
    print('PASS: six isolated stop cases; hard-limit kill is an expected limitation.', flush=True)
finally:
    for name in reversed(NAMES):
        docker('rm', '-f', name, check=False)
    docker('network', 'rm', PREFIX, check=False)
