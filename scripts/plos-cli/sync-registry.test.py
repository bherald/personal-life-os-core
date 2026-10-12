#!/usr/bin/env python3
"""Snapshot publication boundaries; no real SSH or production database access."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('sync_registry', Path(__file__).with_name('sync-registry.py'))
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


class PublicationTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='plos-sync-test-')
        self.addCleanup(self.directory.cleanup)
        self.output = Path(self.directory.name) / 'original snapshot.json'
        self.original = json.dumps({'schema': 'plos.cli.registry.v1', 'source': 'synthetic',
                                   'tables': {'fixture': [{'text': '  José\r\n\t' * 12000}]}},
                                  ensure_ascii=False).encode() + b'\r\n  '
        self.export_calls = 0
        self.staged = None

    def remote(self, command, **kwargs):
        if command[0].lower().endswith('powershell.exe'):
            return subprocess.CompletedProcess(command, 0, b'{"private":true}', b'')
        self.assertEqual(command[0], 'ssh')
        self.assertEqual(command[-2:], ['fixture-source', 'php'])
        self.assertIsInstance(kwargs['input'], bytes)
        self.assertTrue(kwargs['input'].startswith(b'<?php'))
        self.export_calls += 1
        self.staged = kwargs['stdout']
        self.staged.write(self.original)
        return subprocess.CompletedProcess(command, 0, None, b'')

    def invoke(self, network='online', remote=None):
        stdout = io.StringIO()
        with patch.object(sys, 'argv', ['sync-registry.py', '--source', 'fixture-source', '--project', '/srv/plos-demo/release',
                                       '--output', str(self.output), '--network', network]), \
                patch.object(sync.subprocess, 'run', side_effect=remote or self.remote), \
                contextlib.redirect_stdout(stdout):
            sync.main()
        return json.loads(stdout.getvalue())

    def test_remote_source_without_explicit_project_is_refused_before_any_connection(self):
        with patch.object(sys, 'argv', ['sync-registry.py', '--source', 'fixture-source',
                                       '--output', str(self.output), '--network', 'online']), \
                patch.object(sync.subprocess, 'run') as remote, \
                self.assertRaisesRegex(SystemExit, 'explicit approved --project path'):
            sync.main()
        remote.assert_not_called()
        self.assertFalse(self.output.exists())

    def test_local_source_uses_current_installation_and_selected_php_without_ssh(self):
        def local(command, **kwargs):
            self.assertEqual(command, ['/fixture/php'])
            self.assertIn(sync.encoded(str(sync.ROOT).encode()).encode(), kwargs['input'])
            kwargs['stdout'].write(self.original)
            return subprocess.CompletedProcess(command, 0, None, b'')
        stdout = io.StringIO()
        with patch.object(sys, 'argv', ['sync-registry.py', '--source', 'local', '--php', '/fixture/php',
                                       '--output', str(self.output), '--network', 'lan']), \
                patch.object(sync.subprocess, 'run', side_effect=local), contextlib.redirect_stdout(stdout):
            sync.main()
        self.assertFalse(json.loads(stdout.getvalue())['activation_performed'])
        self.assertEqual(self.output.read_bytes(), self.original)

    def test_original_bytes_publish_after_handle_close_and_temporary_file_is_removed(self):
        link = os.link

        def publish(source, destination):
            self.assertTrue(self.staged.closed)
            return link(source, destination)

        with patch.object(sync.os, 'link', side_effect=publish):
            result = self.invoke()
        self.assertEqual(self.output.read_bytes(), self.original)
        self.assertEqual(result['output'], str(self.output.absolute()))
        self.assertEqual(result['sha256'], hashlib.sha256(self.original).hexdigest())
        self.assertEqual(result['source_writes'], 0)
        self.assertFalse(result['activation_performed'])
        self.assertEqual(list(self.output.parent.glob('.sync-*')), [])

    def test_source_failure_closes_and_cleans_staging_without_publication(self):
        def failed(command, **kwargs):
            result = self.remote(command, **kwargs)
            if command[0] == 'ssh':
                result.returncode = 1
                result.stderr = b'PRIVATE_SOURCE_DIAGNOSTIC'
            return result

        with self.assertRaisesRegex(SystemExit, 'previous generation retained'):
            self.invoke(remote=failed)
        self.assertFalse(self.output.exists())
        self.assertTrue(self.staged.closed)
        self.assertEqual(list(self.output.parent.glob('.sync-*')), [])

    def test_known_ssh_failures_have_actionable_diagnostics_without_private_details(self):
        cases = [
            (b'PRIVATE_HOST: Host key verification failed.', 'configure a trusted host key'),
            (b'WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! PRIVATE_PATH', 'configure a trusted host key'),
            (b'PRIVATE_USER@PRIVATE_HOST: Permission denied (publickey,password).', 'configure a host-local identity'),
        ]
        for diagnostic, expected in cases:
            def failed(command, **kwargs):
                result = self.remote(command, **kwargs)
                if command[0] == 'ssh':
                    result.returncode = 255
                    result.stderr = diagnostic
                return result

            with self.subTest(expected=expected), self.assertRaises(SystemExit) as error:
                self.invoke(remote=failed)
            self.assertIn(expected, str(error.exception))
            self.assertNotIn('PRIVATE_', str(error.exception))
            self.assertFalse(self.output.exists())
            self.assertEqual(list(self.output.parent.glob('.sync-*')), [])

    def test_lan_config_probe_cannot_consume_terminal_input(self):
        def configured(command, **kwargs):
            if command[:2] == ['ssh', '-G']:
                self.assertEqual(kwargs['stdin'], subprocess.DEVNULL)
                return subprocess.CompletedProcess(command, 0, 'hostname 10.23.45.10\nport 22\n', '')
            return self.remote(command, **kwargs)

        with patch.object(sync.socket, 'getaddrinfo', return_value=[
                (2, 1, 6, '', ('10.23.45.10', 22))]):
            self.invoke(network='lan', remote=configured)
        self.assertEqual(self.output.read_bytes(), self.original)
        self.assertEqual(self.export_calls, 1)

    def test_explicit_configuration_reaches_both_probe_and_export_as_one_argument(self):
        selected = self.output.parent / 'ssh config ; literal'
        selected.write_text('Host fixture-source\n HostName 10.23.45.10\n')
        observed = []

        def configured(command, **kwargs):
            if command[0] == 'ssh':
                self.assertEqual(command[:3], ['ssh', '-F', str(selected)])
                observed.append(command)
                if '-G' in command:
                    self.assertEqual(kwargs['stdin'], subprocess.DEVNULL)
                    return subprocess.CompletedProcess(command, 0, 'hostname 10.23.45.10\nport 22\n', '')
                self.assertIn('StrictHostKeyChecking=yes', command)
            return self.remote(command, **kwargs)

        with patch.dict(os.environ, {'PLOS_CLI_SYNC_SSH_CONFIG': str(selected)}), \
                patch.object(sync.socket, 'getaddrinfo', return_value=[
                    (2, 1, 6, '', ('10.23.45.10', 22))]):
            self.invoke(network='lan', remote=configured)
        self.assertEqual(len(observed), 2)
        self.assertEqual(self.output.read_bytes(), self.original)

    def test_missing_or_invalid_explicit_configuration_never_falls_back(self):
        for selected in ['', str(self.output.parent / 'missing'), str(self.output.parent)]:
            with self.subTest(selected=selected), patch.dict(os.environ, {'PLOS_CLI_SYNC_SSH_CONFIG': selected}), \
                    self.assertRaisesRegex(SystemExit, 'Selected SSH configuration is unavailable'):
                self.invoke()
        self.assertEqual(self.export_calls, 0)
        self.assertFalse(self.output.exists())

    def test_selected_configuration_cannot_bypass_lan_route_policy(self):
        selected = self.output.parent / 'config'
        selected.write_text('Host fixture-source\n')

        def proxy(command, **kwargs):
            self.assertIn('-G', command)
            return subprocess.CompletedProcess(command, 0,
                'hostname 10.23.45.10\nport 22\nproxycommand ssh other-host\n', '')

        with patch.dict(os.environ, {'PLOS_CLI_SYNC_SSH_CONFIG': str(selected)}), \
                self.assertRaisesRegex(SystemExit, 'direct configured SSH route'):
            self.invoke(network='lan', remote=proxy)
        self.assertEqual(self.export_calls, 0)
        self.assertFalse(self.output.exists())

    @unittest.skipUnless(os.name == 'nt', 'Actual Windows directory and file ACL boundary')
    def test_native_private_directory_creation_and_file_acl(self):
        self.output = self.output.parent / 'native private' / 'snapshot.json'
        execute = subprocess.run

        def native_directory(command, **kwargs):
            if command[0].lower().endswith('powershell.exe'):
                return execute(command, **kwargs)
            return self.remote(command, **kwargs)

        self.invoke(remote=native_directory)
        self.assertEqual(self.output.read_bytes(), self.original)
        # Recheck the existing directory; a rerun cannot depend on ACL repair.
        self.output = self.output.with_name('second snapshot.json')
        self.invoke(remote=native_directory)
        self.assertEqual(self.output.read_bytes(), self.original)

    def test_existing_snapshot_and_offline_mode_never_contact_source(self):
        self.output.write_bytes(b'previous original')
        with self.assertRaisesRegex(SystemExit, 'existing snapshots'):
            self.invoke()
        self.assertEqual(self.output.read_bytes(), b'previous original')
        with self.assertRaisesRegex(SystemExit, 'no source connection'):
            self.invoke(network='offline')
        self.assertEqual(self.export_calls, 0)

    def test_incomplete_export_is_not_published(self):
        self.original = b'{"schema":"wrong","tables":{}}'
        with self.assertRaisesRegex(SystemExit, 'Incomplete registry export'):
            self.invoke()
        self.assertFalse(self.output.exists())
        self.assertEqual(list(self.output.parent.glob('.sync-*')), [])

    def test_source_timeout_closes_and_removes_the_partial_export(self):
        def stalled(command, **kwargs):
            result = self.remote(command, **kwargs)
            if command[0] == 'ssh':
                raise subprocess.TimeoutExpired(command, 120, stderr=b'PRIVATE_DIAGNOSTIC')
            return result

        with self.assertRaisesRegex(SystemExit, '^Registry source timed out; previous generation retained.$'):
            self.invoke(remote=stalled)
        self.assertTrue(self.staged.closed)
        self.assertFalse(self.output.exists())
        self.assertEqual(list(self.output.parent.glob('.sync-*')), [])

    def test_publication_race_preserves_the_other_file(self):
        link = os.link

        def raced(source, destination):
            Path(destination).write_bytes(b'other publisher')
            return link(source, destination)

        with patch.object(sync.os, 'link', side_effect=raced), self.assertRaises(FileExistsError):
            self.invoke()
        self.assertEqual(self.output.read_bytes(), b'other publisher')
        self.assertEqual(list(self.output.parent.glob('.sync-*')), [])


if __name__ == '__main__':
    unittest.main()
