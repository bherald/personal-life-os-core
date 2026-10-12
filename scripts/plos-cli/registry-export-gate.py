#!/usr/bin/env python3
"""Forced SSH command for one host-approved, read-only PLOS registry exporter."""
import hashlib
import json
import os
import pwd
from pathlib import Path
import signal
import stat
import subprocess
import sys


def private_bytes(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, 'rb') as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) & 0o077:
            raise ValueError('Private export authorization is unavailable.')
        return source.read()


def approved_program(directory):
    policy = json.loads(private_bytes(directory / 'export-program.json'))
    if not isinstance(policy, dict) or set(policy) != {'schema', 'bytes', 'sha256'} or policy['schema'] != 'plos.cli.approved-registry-export.v1':
        raise ValueError('Export authorization is invalid.')
    program = private_bytes(directory / 'export.php')
    if type(policy['bytes']) is not int or policy['bytes'] <= 0 or len(program) != policy['bytes'] \
            or hashlib.sha256(program).hexdigest() != policy['sha256']:
        raise ValueError('The authorized exporter changed.')
    return program


def main():
    def expired(_signal, _frame):
        raise TimeoutError('Export input was not completed.')

    try:
        # No arguments, shell, subsystem, alternate PHP options or environment
        # controlled executable are admitted through this restricted key.
        if len(sys.argv) != 1 or os.environ.get('SSH_ORIGINAL_COMMAND') != 'php':
            raise ValueError('This identity permits only the approved registry sync.')
        program = approved_program(Path(__file__).resolve().parent)
        signal.signal(signal.SIGALRM, expired)
        signal.alarm(30)
        try:
            requested = sys.stdin.buffer.read(len(program) + 1)
        finally:
            signal.alarm(0)
        if requested != program:
            raise ValueError('The client exporter needs explicit source authorization refresh.')
        # Execute only the locally stored program, with no forwarded PHP options,
        # credential variables, agent socket, proxy or arbitrary client environment.
        account = pwd.getpwuid(os.getuid())
        result = subprocess.run(['/usr/bin/php'], input=program,
                                env={'PATH': '/usr/bin:/bin', 'HOME': account.pw_dir,
                                     'USER': account.pw_name, 'LOGNAME': account.pw_name, 'LANG': 'C.UTF-8'},
                                cwd=account.pw_dir, timeout=120)
        return result.returncode
    except (OSError, KeyError, ValueError, TimeoutError, subprocess.TimeoutExpired):
        print('Registry export rejected: restricted source authorization or export execution failed.', file=sys.stderr)
        return 65


if __name__ == '__main__':
    raise SystemExit(main())
