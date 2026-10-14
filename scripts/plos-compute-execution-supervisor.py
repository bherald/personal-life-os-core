"""Linux compute custody supervisor; stdout is exclusively the outer envelope.

Child streams go to bounded memory buffers. A terminal receipt is emitted only
when the subreaper has reaped every child. No model or network calls live here.
"""
import argparse
import base64
import ctypes
import hashlib
import json
import os
import re
import selectors
import signal
import socket
import subprocess
import sys
import time


# systemd v255 src/run/run.c starts/waits for the scope before execvpe in the
# same PID. Only this trusted guard executes before the private ACK. Scope mode
# preserves stdin/cwd/environment; restore systemd's added INVOCATION_ID too.
SCOPE_GUARD = r'''
import json,os,select,signal,socket,subprocess,sys
control=socket.socket(fileno=int(sys.argv[1]))
control.settimeout(float(sys.argv[2]))
with open('/proc/self/stat') as stream:
    ticks=stream.read().rsplit(')',1)[1].split()[19]
with open('/proc/self/cgroup') as stream:
    groups=[line.strip()[3:] for line in stream if line.startswith('0::')]
if len(groups)!=1: raise RuntimeError('Unified cgroup required')
ready={'pid':os.getpid(),'start_ticks':ticks,'cgroup':groups[0],
       'invocation_id':os.environ.get('INVOCATION_ID','')}
control.sendall(json.dumps(ready).encode()+b'\n')
data=b''
while b'\n' not in data:
    chunk=control.recv(8192-len(data))
    if not chunk: raise RuntimeError('Scope admission not acknowledged')
    data+=chunk
    if len(data)>=8192: raise RuntimeError('Scope ACK oversized')
ack=json.loads(data)
if ack.get('invocation_id')!=ready['invocation_id']: raise RuntimeError('Foreign scope ACK')
previous=ack['previous_invocation_id']
if previous is None: os.environ.pop('INVOCATION_ID',None)
else: os.environ['INVOCATION_ID']=previous
child=subprocess.Popen(['bash','-lc',sys.argv[3]],env=os.environ)
def forward(sig,frame):
    if child.poll() is None:
        try: os.kill(child.pid,signal.SIGKILL if sig==signal.SIGUSR1 else signal.SIGTERM)
        except ProcessLookupError: pass
signal.signal(signal.SIGTERM,forward)
signal.signal(signal.SIGUSR1,forward)
while child.poll() is None:
    if select.select([control],[],[],.01)[0] and not control.recv(1):
        child.kill()
        child.wait()
        raise RuntimeError('Scope owner disappeared')
status=child.returncode if child.returncode>=0 else 128-child.returncode
control.sendall(json.dumps({'done':status}).encode()+b'\n')
os.close(1)
os.close(2)
control.settimeout(float(sys.argv[4]))
finished=b''
while len(finished)<5:
    part=control.recv(5-len(finished))
    if not part: raise RuntimeError('Scope terminal ACK lost')
    finished+=part
if finished!=b'EXIT\n': raise RuntimeError('Scope terminal proof absent')
os._exit(status)
'''


def scope_limits(value):
    """Reject rather than silently weakening a requested aggregate boundary."""
    fields = {'backend', 'tasks_max', 'memory_max_bytes', 'memory_swap_max_bytes',
              'systemd_run', 'systemctl', 'cgroup_root', 'handshake_timeout_seconds',
              'proof_max_directories', 'proof_max_depth', 'proof_timeout_seconds'}
    if not isinstance(value, dict) or set(value) != fields or value['backend'] != 'systemd_user_scope':
        raise ValueError('Invalid compute scope policy')
    for name in ('tasks_max', 'memory_max_bytes', 'memory_swap_max_bytes', 'handshake_timeout_seconds'):
        if type(value[name]) is not int or value[name] < (0 if name == 'memory_swap_max_bytes' else 1):
            raise ValueError('Invalid compute scope limit')
    for name, minimum, maximum in [('proof_max_directories', 1, 1024), ('proof_max_depth', 0, 32),
                                   ('proof_timeout_seconds', 1, 30)]:
        if type(value[name]) is not int or not minimum <= value[name] <= maximum:
            raise ValueError('Invalid compute scope proof budget')
    for name in ('systemd_run', 'systemctl', 'cgroup_root'):
        if not isinstance(value[name], str) or not os.path.isabs(value[name]) or '\0' in value[name]:
            raise ValueError('Absolute configured scope path required')
    return value


class ScopeBoundary:
    """Invocation-local extra proof, never a substitute for subreaper proof."""
    def __init__(self, policy, owner):
        self.policy = scope_limits(policy)
        if not re.fullmatch(r'[a-fA-F0-9-]{36}', owner):
            raise ValueError('UUID scope owner required')
        self.unit = 'plos-compute-' + owner.lower() + '.scope'
        self.parent, self.guard = socket.socketpair()
        self.parent.settimeout(policy['handshake_timeout_seconds'])
        self.directory = self.events = None
        self.identity = None
        self.done = None
        self.released = False
        self.control_bytes = b''

    def argv(self, command, terminal_timeout):
        p = self.policy
        return [p['systemd_run'], '--user', '--scope', '--quiet', '--no-ask-password',
                '--expand-environment=no', '--unit=' + self.unit,
                '--description=PLOS owned compute scope',
                '--property=TasksMax=' + str(p['tasks_max']),
                '--property=MemoryMax=' + str(p['memory_max_bytes']),
                '--property=MemorySwapMax=' + str(p['memory_swap_max_bytes']),
                '--property=OOMPolicy=kill', '--', sys.executable, '-c', SCOPE_GUARD,
                str(self.guard.fileno()), str(p['handshake_timeout_seconds']), command, str(terminal_timeout)]

    def admit(self, child):
        self.guard.close()
        data = b''
        while b'\n' not in data:
            chunk = self.parent.recv(8192 - len(data))
            if not chunk:
                raise RuntimeError('Scope guard readiness absent')
            data += chunk
            if len(data) >= 8192:
                raise RuntimeError('Scope guard readiness oversized')
        ready = json.loads(data)
        if not isinstance(ready, dict) or set(ready) != {'pid', 'start_ticks', 'cgroup', 'invocation_id'}:
            raise RuntimeError('Invalid scope identity')
        if ready['pid'] != child.pid or not re.fullmatch(r'[0-9a-f]{32}', ready['invocation_id']):
            raise RuntimeError('Foreign scope identity')
        with open('/proc/%d/stat' % child.pid) as stream:
            tail = stream.read().rsplit(')', 1)[1].split()
        if tail[19] != ready['start_ticks'] or int(tail[1]) != os.getpid():
            raise RuntimeError('Scope launcher lineage differs')
        with open('/proc/%d/cgroup' % child.pid) as stream:
            groups = [line.strip()[3:] for line in stream if line.startswith('0::')]
        if groups != [ready['cgroup']]:
            raise RuntimeError('Scope launcher membership differs')
        expected = {'Id': self.unit, 'InvocationID': ready['invocation_id'], 'ControlGroup': ready['cgroup'],
                    'ActiveState': 'active', 'TasksMax': str(self.policy['tasks_max']),
                    'MemoryMax': str(self.policy['memory_max_bytes']),
                    'MemorySwapMax': str(self.policy['memory_swap_max_bytes'])}
        self.expected_properties = expected
        self.inspect_properties(expected)
        self.pin(ready['cgroup'])
        for field, name in [('pids.max', 'tasks_max'), ('memory.max', 'memory_max_bytes'),
                            ('memory.swap.max', 'memory_swap_max_bytes')]:
            if self.read(field).strip() != str(self.policy[name]):
                raise RuntimeError('Kernel scope limit differs')
        if str(child.pid) not in self.read('cgroup.procs').split():
            raise RuntimeError('Owned scope guard absent')
        self.identity = ready
        # ACK happens only after all identity/limit evidence is pinned. No env
        # value is put on argv, in output, in logs, or in the terminal envelope.
        ack = {'invocation_id': ready['invocation_id'],
               'previous_invocation_id': os.environ.get('INVOCATION_ID')}
        wire = json.dumps(ack).encode() + b'\n'
        if len(wire) >= 8192:
            raise RuntimeError('Prior invocation environment exceeds private ACK bound')
        self.parent.sendall(wire)
        self.parent.setblocking(False)

    def inspect_properties(self, expected):
        properties = ['Id', 'InvocationID', 'ControlGroup', 'ActiveState', 'TasksMax', 'MemoryMax', 'MemorySwapMax']
        result = subprocess.run([self.policy['systemctl'], '--user', '--no-pager', 'show', self.unit,
                                 '--property=' + ','.join(properties)], capture_output=True,
                                timeout=self.policy['handshake_timeout_seconds'], check=True)
        if len(result.stdout) > 8192 or len(result.stderr) > 8192:
            raise RuntimeError('Scope property response oversized')
        pairs = [line.split('=', 1) for line in result.stdout.decode('ascii').splitlines()]
        if any(len(pair) != 2 for pair in pairs) or len(pairs) != len(properties):
            raise RuntimeError('Scope properties incomplete')
        values = dict(pairs)
        if values != expected:
            raise RuntimeError('Owned scope properties differ')

    def pin(self, group):
        parts = group.split('/')
        if not group.startswith('/') or parts[-1] != self.unit or any(p in ('.', '..') for p in parts):
            raise RuntimeError('Invalid owned cgroup path')
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
        current = os.open(self.policy['cgroup_root'], flags)
        try:
            for part in parts[1:]:
                if not part:
                    raise RuntimeError('Invalid owned cgroup path component')
                following = os.open(part, flags, dir_fd=current)
                os.close(current)
                current = following
            self.directory = current
            current = None
            self.events = os.open('cgroup.events', os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC,
                                  dir_fd=self.directory)
        finally:
            if current is not None:
                os.close(current)

    def read(self, field, directory=None):
        fd = os.open(field, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC,
                     dir_fd=self.directory if directory is None else directory)
        try:
            value = os.read(fd, 8193)
            if len(value) > 8192:
                raise RuntimeError('Cgroup proof oversized')
            return value.decode('ascii')
        finally:
            os.close(fd)

    def owned_guard(self, pid):
        if self.identity is None or pid != self.identity['pid']:
            return False
        with open('/proc/%d/stat' % pid) as stream:
            tail = stream.read().rsplit(')', 1)[1].split()
        with open('/proc/%d/cgroup' % pid) as stream:
            groups = [line.strip()[3:] for line in stream if line.startswith('0::')]
        if tail[19] != self.identity['start_ticks'] or int(tail[1]) != os.getpid() or groups != [self.identity['cgroup']]:
            raise RuntimeError('Pinned guard identity changed')
        return True

    def receive_done(self):
        if self.done is not None:
            return
        try:
            chunk = self.parent.recv(8192 - len(self.control_bytes))
        except BlockingIOError:
            return
        if not chunk:
            raise RuntimeError('Scope guard died before terminal handshake')
        self.control_bytes += chunk
        if len(self.control_bytes) >= 8192:
            raise RuntimeError('Scope terminal handshake oversized')
        if b'\n' not in self.control_bytes:
            return
        message = json.loads(self.control_bytes)
        if not isinstance(message, dict) or set(message) != {'done'} or type(message['done']) is not int or not 0 <= message['done'] <= 255:
            raise RuntimeError('Scope payload status invalid')
        self.done = message['done']

    def release_guard(self, owned_children):
        if self.done is None or self.identity is None or self.directory is None or self.events is None:
            raise RuntimeError('Scope terminal evidence missing')
        if owned_children != [self.identity['pid']] or not self.owned_guard(owned_children[0]):
            raise RuntimeError('Scope descendants remain unreaped')
        os.lseek(self.events, 0, os.SEEK_SET)
        value = os.read(self.events, 8193).decode('ascii')
        if len(value) > 8192 or dict(line.split() for line in value.splitlines()).get('populated') != '1':
            raise RuntimeError('Scope guard membership unproven')
        # The exact trusted guard retains the scope through this proof. It has
        # reaped its payload and can only exit after ACK; it cannot fork again.
        self.inspect_properties(self.expected_properties)
        proof = {'remaining': self.policy['proof_max_directories'],
                 'deadline': time.monotonic() + self.policy['proof_timeout_seconds']}
        self.verify_directory(self.directory, {str(owned_children[0])}, proof)
        self.proof_budget(proof, 0)
        self.parent.sendall(b'EXIT\n')
        self.released = True

    def proof_budget(self, proof, depth):
        if time.monotonic() >= proof['deadline'] or depth > self.policy['proof_max_depth']:
            raise RuntimeError('Cgroup proof deadline or depth budget exhausted')

    def verify_directory(self, directory, expected, proof, depth=0):
        self.proof_budget(proof, depth)
        if proof['remaining'] < 1:
            raise RuntimeError('Cgroup proof directory budget exhausted')
        proof['remaining'] -= 1
        if set(self.read('cgroup.procs', directory).split()) != expected:
            raise RuntimeError('Owned cgroup tasks differ')
        self.proof_budget(proof, depth)
        # Stream entries rather than allocating a directory-sized list. Check
        # budget/deadline before iterator reads, descent, and each bounded read.
        with os.scandir(directory) as entries:
            while True:
                self.proof_budget(proof, depth)
                try:
                    entry = next(entries)
                except StopIteration:
                    break
                self.proof_budget(proof, depth)
                if not entry.is_dir(follow_symlinks=False):
                    continue
                if proof['remaining'] < 1:
                    raise RuntimeError('Cgroup proof directory budget exhausted')
                self.proof_budget(proof, depth + 1)
                fd = os.open(entry.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory)
                try:
                    self.verify_directory(fd, set(), proof, depth + 1)
                finally:
                    os.close(fd)

    def verify_terminal(self, leader_status):
        if not self.released or self.done is None or leader_status != self.done:
            raise RuntimeError('Owned scope terminal completion unproven')

    def signal_owned_child(self, pid, sig):
        if self.owned_guard(pid):
            if self.done is None:
                # Retain the pinned guard; it forwards this to its own unreaped
                # payload leader. Once DONE, the guard can only receive EXIT.
                os.kill(pid, signal.SIGTERM if sig == signal.SIGTERM else signal.SIGUSR1)
        else:
            os.kill(pid, sig)

    def close(self):
        self.parent.close()
        self.guard.close()
        for fd in (self.events, self.directory):
            if fd is not None:
                os.close(fd)


def envelope(args, dispatched, exit_code, stdout=b"", stderr=b""):
    return {
        "schema": "plos.compute_terminal.v1",
        "owner_token": args.owner_token,
        "command_sha256": hashlib.sha256(args.command.encode()).hexdigest(),
        "cleanup_verified": True,
        "dispatched": dispatched,
        "exit_code": exit_code,
        "stdout_base64": base64.b64encode(stdout).decode("ascii"),
        "stderr_base64": base64.b64encode(stderr).decode("ascii"),
    }


def children():
    # This single-threaded supervisor owns these children until waitpid reaps
    # them; unreaped child PIDs cannot be recycled into unrelated processes.
    with open("/proc/self/task/%d/children" % os.getpid(), encoding="ascii") as stream:
        return [int(value) for value in stream.read().split()]


def emit(value):
    sys.stdout.write(json.dumps(value, separators=(",", ":"), sort_keys=True) + "\n")
    sys.stdout.flush()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--owner-token", default="")
    parser.add_argument("--timeout", required=True, type=float)
    parser.add_argument("--cleanup-grace", required=True, type=float)
    parser.add_argument("--output-max-bytes", required=True, type=int)
    parser.add_argument("--command")
    parser.add_argument("--transport-argv")
    parser.add_argument("--scope-limits")
    args = parser.parse_args()
    if args.timeout <= 0 or args.cleanup_grace <= 0 or args.output_max_bytes < 1:
        raise ValueError("Positive deadlines required")
    relay = args.transport_argv is not None
    boundary = None
    if relay and args.scope_limits is not None:
        raise ValueError("Scope boundary belongs to payload, not transport")
    if relay:
        target = json.loads(args.transport_argv)
        if not isinstance(target, list) or not target or not all(isinstance(value, str) for value in target):
            raise ValueError("Invalid transport argv")
    else:
        if args.command is None or not args.owner_token:
            raise ValueError("Exact execution command and owner required")
        target = ["bash", "-lc", args.command]

    # All potentially unsupported setup precedes Popen. ECHILD proves no child
    # was inherited. A setup failure may report proven no-dispatch, never an
    # uncertain Popen failure that could already have forked a child.
    try:
        if not sys.platform.startswith("linux"):
            raise RuntimeError("Linux subreaper required")
        libc = ctypes.CDLL(None, use_errno=True)
        if libc.prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
            raise OSError(ctypes.get_errno(), "subreaper setup failed")
        enabled = ctypes.c_int()
        if libc.prctl(37, ctypes.byref(enabled), 0, 0, 0) != 0 or enabled.value != 1:
            raise RuntimeError("subreaper verification failed")
        if children():
            raise RuntimeError("unexpected inherited child")
        try:
            os.waitpid(-1, os.WNOHANG)
        except ChildProcessError:
            pass
        else:
            raise RuntimeError("unexpected inherited child")
        selector = selectors.DefaultSelector()
        output = [bytearray(), bytearray()]
        if args.scope_limits is not None:
            boundary = ScopeBoundary(json.loads(args.scope_limits), args.owner_token)
            target = boundary.argv(args.command, args.timeout + args.cleanup_grace + boundary.policy['handshake_timeout_seconds'])
    except Exception:
        # The supervisor has never invoked a child launch at this point.
        if not relay:
            emit(envelope(args, False, 125))
        return 125 if relay else 0

    with selector:
        child = subprocess.Popen(
            target, stdin=sys.stdin.buffer,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True,
            pass_fds=(boundary.guard.fileno(),) if boundary is not None else (),
        )
        if boundary is not None:
            try:
                boundary.admit(child)
            except Exception:
                # Only our exact unreaped direct child; no payload ACK was sent.
                child.kill()
                child.wait()
                boundary.close()
                raise
        for index, stream in enumerate([child.stdout, child.stderr]):
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ, index)
        captured = 0
        overflow = False
        deadline = time.monotonic() + args.timeout
        cleanup_started = None
        leader_status = None
        timed_out = False
        while True:
            for key, _ in selector.select(timeout=0):
                # Bound each drain batch so a noisy child cannot starve the
                # deadline, while draining completed large streams promptly.
                for _ in range(16):
                    try:
                        data = os.read(key.fd, 65536)
                    except BlockingIOError:
                        break
                    if not data:
                        selector.unregister(key.fileobj)
                        key.fileobj.close()
                        break
                    remaining = args.output_max_bytes - captured
                    retained = data[:remaining]
                    if relay:
                        # This trusted local relay bounds BOTH transport streams
                        # before Symfony can aggregate an arbitrary remote burst.
                        stream = sys.stdout.buffer if key.data == 0 else sys.stderr.buffer
                        stream.write(retained)
                        stream.flush()
                    else:
                        output[key.data].extend(retained)
                    captured += len(retained)
                    overflow = overflow or len(data) > remaining
            all_reaped = False
            while True:
                try:
                    pid, status = os.waitpid(-1, os.WNOHANG)
                except ChildProcessError:
                    all_reaped = True
                    break
                if pid == 0:
                    break
                if pid == child.pid:
                    if os.WIFEXITED(status):
                        leader_status = os.WEXITSTATUS(status)
                    elif os.WIFSIGNALED(status):
                        leader_status = -os.WTERMSIG(status)
                    else:
                        raise RuntimeError("nonterminal leader status")
                    child.returncode = leader_status
            if boundary is not None and not boundary.released:
                boundary.receive_done()
                owned = children()
                if boundary.done is not None and owned == [child.pid] and not selector.get_map():
                    boundary.release_guard(owned)
            if all_reaped and not selector.get_map():
                if boundary is not None:
                    boundary.verify_terminal(leader_status)
                    boundary.close()
                if overflow:
                    if relay:
                        return 125
                    raise RuntimeError("compute output capture overflow")
                if leader_status is None:
                    raise RuntimeError("leader status missing")
                code = 124 if timed_out else leader_status
                if code < 0:
                    code = 128 - code
                if relay:
                    return code
                emit(envelope(args, True, code, bytes(output[0]), bytes(output[1])))
                return 0
            now = time.monotonic()
            if cleanup_started is None and (leader_status is not None or (boundary is not None and boundary.done is not None) or now >= deadline or overflow):
                timed_out = leader_status is None and (boundary is None or boundary.done is None)
                cleanup_started = now
            if cleanup_started is not None:
                # Kill only currently owned, unreaped direct children; adoption
                # brings escaped sessions and double-forked children back here.
                # Repeated rounds also handle descendants of a killed parent.
                elapsed = now - cleanup_started
                sig = signal.SIGTERM if elapsed < args.cleanup_grace / 2 else signal.SIGKILL
                for pid in children():
                    try:
                        if boundary is not None:
                            boundary.signal_owned_child(pid, sig)
                        else:
                            os.kill(pid, sig)
                    except ProcessLookupError:
                        pass
                if elapsed >= args.cleanup_grace:
                    # No terminal envelope while any child remains. Router
                    # keeps its exact persisted hold, even if this helper dies.
                    raise RuntimeError("compute cleanup unproven")
            time.sleep(0.01)


if __name__ == "__main__":
    try:
        status = main()
    except Exception:
        if "--transport-argv" in sys.argv:
            # Relay errors never append uncapped diagnostics after forwarding
            # its quota. Nonzero exit is sufficient for retained UNKNOWN.
            raise SystemExit(125)
        raise
    raise SystemExit(status)
