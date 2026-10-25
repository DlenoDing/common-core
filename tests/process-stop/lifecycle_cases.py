"""Additional real lifecycle regressions. Uses run.py's isolated services only."""
import json
import subprocess
import time


def lifecycle_specs():
    return [
        dict(case='queue-repeat-term', mode='queue', seconds=7, grace=35, resources=1, defer=1, lifecycle='repeat', repeat='SIGTERM'),
        dict(case='amqp-repeat-int', mode='amqp', seconds=7, grace=35, resources=1, defer=1, lifecycle='repeat', repeat='SIGINT', heartbeat='omit'),
        dict(case='amqp-repeat-pid1', mode='amqp', seconds=7, grace=35, resources=1, defer=1, lifecycle='repeat', repeat='SIGTERM', repeat_target='container', heartbeat='omit'),
        dict(case='cron-repeat-term', mode='cron', seconds=7, grace=35, resources=1, defer=1, worker_cron=1, server_mode='process', lifecycle='repeat', repeat='SIGTERM'),
        dict(case='cron-max-request-base', mode='cron', seconds=7, jobs=2, grace=35, resources=1, defer=1, worker_cron=1, lifecycle='recycle'),
        dict(case='cron-max-request-process', mode='cron', seconds=7, jobs=2, grace=35, resources=1, defer=1, worker_cron=1, server_mode='process', lifecycle='recycle'),
        dict(case='broker-pause-inflight', mode='amqp', seconds=7, grace=40, resources=1, defer=1, lifecycle='outage', outage='pause-resume', heartbeat='omit', minimal_params=1, custom_io=1),
        dict(case='broker-paused-close', mode='amqp', seconds=1, grace=45, defer=1, lifecycle='outage', outage='pause', heartbeat='omit', minimal_params=1, custom_io=1, worker_wait=60, expected_outcome='framework-cleanup-incomplete'),
        dict(case='amqp-repeat-cleanup-pid1', mode='amqp', seconds=1, grace=45, defer=1, lifecycle='outage', outage='pause', heartbeat='omit', minimal_params=1, custom_io=1, worker_wait=60, late_repeat='container', expected_outcome='framework-cleanup-incomplete'),
        dict(case='broker-crash-before-stop', mode='amqp', seconds=1, grace=45, defer=1, lifecycle='outage', outage='kill', heartbeat='omit', minimal_params=1, custom_io=1, worker_wait=60, expected_outcome='error-path-local-close'),
        dict(case='broker-network-close', mode='amqp', seconds=1, grace=45, defer=1, lifecycle='outage', outage='disconnect', heartbeat='omit', minimal_params=1, custom_io=1, worker_wait=60, expected_outcome='framework-cleanup-incomplete'),
        dict(case='broker-paused-close-tight', mode='amqp', seconds=1, grace=10, defer=1, lifecycle='outage', outage='pause', heartbeat='omit', minimal_params=1, custom_io=1, worker_wait=60, expected_outcome='external-stop-bound'),
    ]


def run_lifecycle_case(spec, name, out, http_before, c):
    docker, events, wait_for = c.docker, c.events, c.wait_for
    mode, case = spec['mode'], spec['case']
    kind = spec['lifecycle']
    jobs = spec.get('jobs', 1)
    actions = []
    outage_active = False
    outage_ip = None
    outage_ended = None
    resources_checked = False

    def data():
        return events(out)

    def action(event, **values):
        actions.append(dict(event=event, time=time.time(), **values))
        (out / 'actions.json').write_text(json.dumps(actions, indent=2))

    def queues():
        return docker('exec', c.rabbit, 'rabbitmqctl', '-q', 'list_queues', 'name', 'messages_ready', 'messages_unacknowledged')

    def queue_empty(snapshot):
        row = next(line.split() for line in snapshot.splitlines() if line.startswith(case + '\t'))
        assert row[1:] == ['0', '0'], snapshot

    def check_resources():
        nonlocal resources_checked
        es = data()
        if not spec.get('resources') or resources_checked:
            return
        acquired = next((e for e in es if e['event'] == 'resources-acquired' and e['id'] == '0'), None)
        if not acquired or time.time() - acquired['time'] < 5.5 or any(e['event'] == 'job-done' and e.get('id') == '0' for e in es):
            return
        import hashlib
        stem = hashlib.sha1((case + '-0* * * * *').encode()).hexdigest()
        for key in ['{audit-' + case + '-0}', 'framework/crontab-' + stem, 'hyperf/crontab-' + stem + '-sv']:
            assert c.redis_call('set', key, 'competitor', 'NX', 'EX', 2) == '', 'Lock lost: ' + key
        resources_checked = True
        action('locks-probed')

    def restore_broker():
        nonlocal outage_active, outage_ended
        if not outage_active:
            return
        if spec['outage'].startswith('pause'):
            docker('unpause', c.rabbit)
        elif spec['outage'] == 'kill':
            docker('start', c.rabbit)
            def ready():
                try:
                    docker('exec', c.rabbit, 'rabbitmq-diagnostics', '-q', 'check_running')
                    queues()
                    return True
                except RuntimeError:
                    return False
            wait_for(ready, 'Rabbit application fully restarted', 60)
        else:
            docker('network', 'connect', '--alias', 'rabbit', '--ip', outage_ip, c.prefix, c.rabbit)
        outage_active = False
        outage_ended = time.time()
        action('broker-restored')

    stop = None
    started = None
    try:
        wait_for(lambda: any(e['event'] == 'process-ready' for e in data()), case + ' ready')
        wait_for(lambda: any(e['event'] == 'job-start' and e['id'] == '0' for e in data())
                 and len(events(c.http_out, 'http.jsonl')) > http_before, 'first task slow HTTP')
        old_pid = next(e['pid'] for e in data() if e['event'] == 'job-start' and e['id'] == '0')

        if kind == 'recycle':
            action('max-request-trigger', pid=old_pid)
            reply = docker('exec', name, 'php', '-r', '$body = file_get_contents("http://127.0.0.1:9501/rotate"); echo json_encode(["status" => $http_response_header[0], "body" => json_decode($body, true)]);')
            received = json.loads(reply)
            assert '200' in received['status'] and received['body']['pid'] == old_pid, reply
            wait_for(lambda: any(e['event'] == 'native-worker-exit' and e['pid'] == old_pid and e['running'] > 0 for e in data()), 'max_request native exit')
            deadline = time.monotonic() + 30
            while not any(e['event'] == 'task-defer-done' and e['id'] == '0' for e in data()):
                assert time.monotonic() < deadline, data()
                check_resources()
                time.sleep(.1)
            wait_for(lambda: any(e['event'] == 'task-defer-done' and e['id'] == '1' for e in data()), 'replacement worker executes next task', 25)
            action('recycle-completed')
        elif kind == 'outage':
            if spec['outage'] != 'pause-resume':
                wait_for(lambda: any(e['event'] == 'task-defer-done' for e in data()), 'task cleanup before permanent outage')
                wait_for(lambda: any(e['event'] == 'amqp-business-returned' for e in data()), 'AMQP business return')
                before = queues()
                queue_empty(before)
                (out / 'broker-before-outage.txt').write_text(before)
                action('broker-ack-verified')
            else:
                wait_for(lambda: any(e['event'] == 'resources-acquired' for e in data()), 'resources acquired before outage')
            if spec['outage'] == 'disconnect':
                info = json.loads(docker('inspect', c.rabbit))[0]
                outage_ip = info['NetworkSettings']['Networks'][c.prefix]['IPAddress']
                docker('network', 'disconnect', c.prefix, c.rabbit)
            elif spec['outage'] == 'kill':
                docker('kill', c.rabbit)
            else:
                docker('pause', c.rabbit)
            outage_active = True
            action('broker-unavailable', mechanism=spec['outage'])
            if spec['outage'] == 'kill':
                wait_for(lambda: any(e['event'] == 'amqp-read-error' and not e['closing'] for e in data())
                         and any(e['event'] == 'handle-returned' for e in data()), 'broker crash detected before stop')
                action('pre-stop-error-returned')

        stop_wall = time.time()
        started = time.monotonic()
        action('docker-stop', grace=spec['grace'])
        stop = subprocess.Popen(['docker', 'stop', '--timeout', str(spec['grace']), name], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        repeat_sent = False
        late_sent = False
        deadline = started + spec['grace'] + 12
        while stop.poll() is None:
            assert time.monotonic() < deadline, 'Docker stop exceeded its guard'
            check_resources()
            es = data()
            if kind == 'repeat' and not repeat_sent:
                first = [e for e in es if e['event'] == ('native-worker-exit' if spec.get('worker_cron') else 'observed-stop-signal') and e['pid'] == old_pid]
                if first and time.time() - first[0]['time'] > .3:
                    assert not any(e['event'] == 'job-done' for e in es), es
                    if spec.get('repeat_target') == 'container':
                        docker('kill', '--signal', spec['repeat'].removeprefix('SIG'), name)
                    else:
                        docker('exec', name, 'kill', '-' + spec['repeat'].removeprefix('SIG'), old_pid)
                    docker('exec', name, 'kill', '-0', old_pid)
                    action('second-signal-sent', pid=old_pid, signal=spec['repeat'])
                    repeat_sent = True
            if spec.get('late_repeat') and not late_sent:
                returned = next((e for e in es if e['event'] == 'handle-returned'), None)
                if returned and time.time() - returned['time'] > 1:
                    assert returned['signal_manager_stopped'] is True, returned
                    docker('kill', '--signal', 'TERM', name)
                    action('cleanup-second-term-pid1', pid=1, after_handle_seconds=time.time() - returned['time'])
                    late_sent = True
            if kind == 'outage' and spec['outage'] == 'pause-resume' and outage_active and time.monotonic() - started >= 9:
                if any(e['event'] == 'publish-confirm-wait' for e in es):
                    assert not any(e['event'] == 'publish-confirmed' for e in es), es
                    assert json.loads(docker('inspect', c.rabbit))[0]['State']['Paused'] is True
                    action('confirm-blocked-while-paused')
                    restore_broker()
            time.sleep(.1)
        _, err = stop.communicate()
        assert stop.returncode == 0, err.decode()
        elapsed = time.monotonic() - started
        state = json.loads(docker('inspect', name))[0]['State']
        es = data()
        names = [e['event'] for e in es]
        calls = events(c.http_out, 'http.jsonl')[http_before:]
        logs = docker('logs', name, check=False) + ((out / 'swoole.log').read_text() if (out / 'swoole.log').exists() else '')
        (out / 'container.log').write_text(logs)
        restore_broker()
        after = queues()
        (out / 'broker-queues.txt').write_text(after)

        assert names.count('job-start') == jobs and names.count('job-done') == jobs, es
        assert names.count('task-defer-done') == jobs and names.count('http-ok') == jobs * 2 and len(calls) == jobs * 3, es
        assert 'job-error' not in names, es
        assert c.redis_call('zcard', '{' + case + '}:reserved') == '0'
        if mode == 'queue':
            assert names.count('acked') == jobs, es
        if kind == 'outage':
            io = [e for e in es if e['event'] == 'custom-io']
            assert len(io) == 1 and io[0]['heartbeat'] == 15 and io[0]['read_write_timeout'] == 30 and io[0]['connection_timeout'] == 3, es
        if mode == 'amqp':
            queue_empty(after)
            deliveries = [e for e in es if e['event'] == 'amqp-delivery']
            assert len(deliveries) == jobs and not any(e['redelivered'] for e in deliveries), es
        if spec.get('resources'):
            assert resources_checked and names.count('resources-retained') == jobs and names.count('locks-unlocked') == jobs, es
            assert 'publish-' + case + '\t' + str(jobs) + '\t0' in after, after

        permanent = kind == 'outage' and spec['outage'] != 'pause-resume'
        if not permanent:
            assert state['ExitCode'] == 0 and elapsed < spec['grace'], state
            assert 'forced termination' not in logs and 'deadlock' not in logs.lower(), logs
            assert not [e for e in es if e['event'] == 'log' and e['level'] in ['error', 'critical']], es
        if not spec.get('worker_cron'):
            closing = next(e['time'] for e in es if e['event'] == 'exit-coordinator')
            assert all(e['time'] <= closing for e in es if e['event'] in ['job-done','acked','amqp-business-returned','task-defer-done','locks-unlocked']), es
        else:
            assert all(e.get('worker_signal_handlers', 0) == 0 for e in es if e['event'] == 'worker-start'), es
            for pid in {e['pid'] for e in es if e['event'] == 'job-start'}:
                local = [e for e in es if e['pid'] == pid]
                parents = [e for e in local if e['event'] == 'parent-worker-exit']
                assert parents and all(e['cid'] == -1 and e['running'] == 0 for e in parents), local
                assert parents[0]['time'] >= max(e['time'] for e in local if e['event'] in ['task-defer-done','locks-unlocked']), local
            assert 'future-worker-task' not in names, es

        if kind == 'repeat':
            assert repeat_sent, es
            if not spec.get('worker_cron'):
                observed = [e for e in es if e['event'] == 'observed-stop-signal']
                expected = 15 if spec['repeat'] == 'SIGTERM' else 2
                if spec.get('repeat_target') == 'container':
                    assert observed and observed[0]['signal'] == 15, es
                else:
                    assert len(observed) == 2 and [e['signal'] for e in observed] == [15, expected], es
                assert all(e['running'] is False for e in observed), es
            assert next(a['time'] for a in actions if a['event'] == 'second-signal-sent') < next(e['time'] for e in es if e['event'] == 'job-done'), es
        if kind == 'recycle':
            new_job = next(e for e in es if e['event'] == 'job-start' and e['id'] == '1')
            assert new_job['pid'] != old_pid, es
            starts = [e for e in es if e['event'] == 'worker-start']
            assert len(starts) == 2 and all(e['running_count'] == 0 for e in starts), es
            requests = [e for e in es if e['event'] == 'rotation-request']
            assert len(requests) == 1 and requests[0]['uri'] == '/rotate' and requests[0]['pid'] == old_pid, es
            assert next(e['time'] for e in es if e['event'] == 'native-worker-exit' and e['pid'] == old_pid) < next(e['time'] for e in es if e['event'] == 'job-done' and e['id'] == '0'), es
        if kind == 'outage' and spec['outage'] == 'pause-resume':
            assert outage_ended is not None, actions
            wait = next(e['time'] for e in es if e['event'] == 'publish-confirm-wait')
            confirmed = next(e['time'] for e in es if e['event'] == 'publish-confirmed')
            assert wait < confirmed and any(a['event'] == 'confirm-blocked-while-paused' for a in actions), es

        if spec.get('late_repeat'): assert late_sent, es
        assert 'TypeError:' not in logs and 'Parse error' not in logs, logs
        io_closes = [e for e in es if e['event'] == 'amqp-io-closed']
        process_end = next((e['time'] for e in es if e['event'] == 'process-exit'), float('inf'))
        normal_close = any(e['time'] < process_end and e['cid'] > 0 for e in io_closes)
        outcome = 'normal'
        if permanent:
            assert state['ExitCode'] in [0, 137], state
            assert any(e['event'] == 'broker-ack-verified' for e in actions), actions
            assert all(e['time'] < stop_wall for e in es if e['event'] in ['job-done','task-defer-done']), es
            if state['ExitCode'] == 137:
                outcome = 'external-stop-bound'
            elif 'deadlock' in logs.lower():
                assert any(e['event'] == 'amqp-read-error' for e in es), es
                outcome = 'framework-cleanup-incomplete'
            elif normal_close and 'forced termination' not in logs:
                outcome = 'error-path-local-close'
            elif spec.get('late_repeat') and 'process-exit' not in names:
                outcome = 'cleanup-interrupted-by-second-signal'
            else:
                raise AssertionError('Unclassified shutdown failure: ' + logs)
            for event in es:
                last = event.get('last_error')
                if last and last.get('type') in [1, 4, 16, 64, 256, 4096]:
                    assert 'deadlock' in str(last).lower(), last
            assert 'Allowed memory size' not in logs, logs

        if kind == 'outage' and spec['outage'] == 'kill':
            closing_event = next(e for e in es if e['event'] == 'exit-coordinator')
            assert closing_event['running'] is True and closing_event['time'] < stop_wall, es
            assert any(e['event'] == 'log' and 'Recv loop broken' in e['message'] for e in es), es
        assert outcome == spec.get('expected_outcome', 'normal'), (case, outcome, spec.get('expected_outcome'), logs)
        rotations = [a for a in actions if a['event'] == 'max-request-trigger']
        completed = [a for a in actions if a['event'] == 'recycle-completed']
        return dict(case=case, category=kind, stop_seconds=round(elapsed,3), grace=spec['grace'], exit_code=state['ExitCode'],
                    jobs=jobs, http_requests=len(calls), reserved=0, outcome=outcome, expected_outcome=spec.get('expected_outcome','normal'),
                    recycle_seconds=round(completed[0]['time'] - rotations[0]['time'], 3) if rotations else None,
                    job_pids={e['id']:e['pid'] for e in es if e['event'] == 'job-start'},
                    process_exit_observed='process-exit' in names,
                    io_close_observed=normal_close if kind == 'outage' else None,
                    read_errors=len([e for e in es if e['event'] == 'amqp-read-error']), observed_signals=[e['signal'] for e in es if e['event'] == 'observed-stop-signal'],
                    deadlock='deadlock' in logs.lower(), forced_termination='forced termination' in logs)
    finally:
        restore_broker()
        if stop and stop.poll() is None:
            docker('kill', name, check=False)
            stop.communicate(timeout=10)
