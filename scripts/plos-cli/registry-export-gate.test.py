import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class ExportGateTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='plos-export-gate-')
        self.root = Path(self.temporary.name)
        shutil.copyfile(Path(__file__).with_name('registry-export-gate.py'), self.root/'gate.py')
        self.original = ('  José\r\n\t'.encode()*10000)+b' original tail  '
        self.program = b'<?php if (getenv("PLOS_SYNTHETIC_SECRET") !== false) exit(12); echo base64_decode("' \
            + __import__('base64').b64encode(self.original)+b'");'
        self.write_policy(self.program)

    def tearDown(self):
        self.temporary.cleanup()

    def write_policy(self, program):
        (self.root/'export.php').write_bytes(program)
        (self.root/'export-program.json').write_text(json.dumps({'schema': 'plos.cli.approved-registry-export.v1',
            'bytes': len(program), 'sha256': hashlib.sha256(program).hexdigest()}))
        for name in ['export.php', 'export-program.json']:
            (self.root/name).chmod(0o600)

    def run_gate(self, program=None, command='php', extra=()):
        env = dict(os.environ, SSH_ORIGINAL_COMMAND=command, PLOS_SYNTHETIC_SECRET='test-must-not-reach-php')
        return subprocess.run(['/usr/bin/python3', str(self.root/'gate.py'), *extra],
                              input=self.program if program is None else program,
                              capture_output=True, env=env, timeout=5)

    def test_exact_export_preserves_complete_output_and_removes_inherited_secrets(self):
        result = self.run_gate()
        self.assertEqual(0, result.returncode)
        self.assertEqual(self.original, result.stdout)
        self.assertEqual(b'', result.stderr)

    def test_shell_subsystem_arguments_and_altered_php_are_refused(self):
        for command in ['', 'sh', 'sftp', 'php -r "echo 1;"', 'php; id']:
            result = self.run_gate(command=command)
            self.assertEqual(65, result.returncode)
            self.assertEqual(b'', result.stdout)
        for data in [b'<?php echo "UNAPPROVED";', self.program+b' ', self.program[:-1]]:
            result = self.run_gate(data)
            self.assertEqual(65, result.returncode)
            self.assertEqual(b'', result.stdout)
        self.assertEqual(65, self.run_gate(extra=['--override']).returncode)

    def test_changed_or_nonprivate_source_authorization_cannot_execute(self):
        (self.root/'export.php').write_bytes(self.program+b' ')
        self.assertEqual(65, self.run_gate().returncode)
        self.write_policy(self.program)
        (self.root/'export-program.json').chmod(0o644)
        self.assertEqual(65, self.run_gate().returncode)
        self.write_policy(self.program)
        (self.root/'export.php').rename(self.root/'other.php')
        (self.root/'export.php').symlink_to(self.root/'other.php')
        self.assertEqual(65, self.run_gate().returncode)


if __name__ == '__main__':
    unittest.main()
