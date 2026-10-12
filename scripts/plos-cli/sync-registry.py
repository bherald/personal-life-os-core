#!/usr/bin/env python3
"""Read a consistent production configuration snapshot over SSH, without deployment."""
import argparse
import base64
import hashlib
import json
import os
import ipaddress
from pathlib import Path
import re
import subprocess
import socket
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def encoded(value):
    return base64.b64encode(value).decode('ascii')


def export_program(manifest, project, include_schema=False):
    sources = [ROOT / 'app/Services/UnifiedCli' / (name + '.php') for name in
               ['CliSettingValue', 'CliMcpServerDefinitions', 'RegistrySnapshotValidator', 'RegistrySnapshotExporter', 'CliSkillBundleExporter']]
    bundle = b'\n'.join(path.read_bytes()[5:] for path in sources)
    namespace = b'PLOS\\CliExport\\V' + hashlib.sha256(bundle).hexdigest()[:16].encode()
    bundle = bundle.replace(b'App\\Services\\UnifiedCli', namespace)
    exporter = namespace.decode() + '\\RegistrySnapshotExporter'
    skill_exporter = namespace.decode() + '\\CliSkillBundleExporter'
    validator = namespace.decode() + '\\RegistrySnapshotValidator'
    mcp_exporter = namespace.decode() + '\\CliMcpServerDefinitions'
    skeleton = '''
    $db = Illuminate\\Support\\Facades\\DB::connection();
    $migrationBefore = $db->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->map(fn ($row) => (array) $row)->all();
    $definitions = [];
    $objects = $db->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME', [$db->getDatabaseName()]);
    foreach ($objects as $object) {
        if ($object->type !== 'BASE TABLE' || !preg_match('/^[a-zA-Z0-9_]+$/D', $object->name)) {
            throw new RuntimeException('Unsupported schema object; no dev replacement performed.');
        }
        $row = (array) $db->selectOne('SHOW CREATE TABLE `'.$object->name.'`');
        $definitions[$object->name] = $row['Create Table'];
    }
    foreach (['triggers' => 'TRIGGER_SCHEMA', 'routines' => 'ROUTINE_SCHEMA', 'events' => 'EVENT_SCHEMA'] as $kind => $column) {
        if ((int)$db->selectOne('SELECT COUNT(*) AS n FROM information_schema.'.$kind.' WHERE '.$column.' = ?', [$db->getDatabaseName()])->n !== 0) {
            throw new RuntimeException('Executable schema objects need explicit dev handling.');
        }
    }
    $migrationAfter = $db->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->map(fn ($row) => (array) $row)->all();
    if ($migrationBefore !== $migrationAfter) {
        throw new RuntimeException('Production migrations changed during schema export.');
    }
    $snapshot['mysql_skeleton'] = [
        'schema' => 'plos.dev.mysql-skeleton.v1',
        'source_database' => $db->getDatabaseName(),
        'source_server_uuid' => $db->selectOne('SELECT @@server_uuid AS id')->id,
        'tables' => $definitions,
        'migrations' => $migrationAfter,
        'operational_rows_exported' => 0,
    ];
''' if include_schema else ''
    return f'''<?php
error_reporting(0);
ob_start();
try {{
    chdir(base64_decode('{encoded(project.encode())}'));
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    eval(base64_decode('{encoded(bundle)}'));
    $manifest = json_decode(base64_decode('{encoded(manifest)}'), true, flags: JSON_THROW_ON_ERROR);
    $snapshot = (new {exporter})->export(Illuminate\\Support\\Facades\\DB::connection(), $manifest);
    $snapshot['skill_bundles'] = (new {skill_exporter})->export(base_path(config('agents.skills_path', 'resources/agents/skills')), $snapshot['tables']['skill_versions']);
    if (($snapshot['definition_sources']['mcp_server_definitions'] ?? null) === 'missing') {{
        $snapshot['tables']['mcp_server_definitions'] = (new {mcp_exporter})->fromConfiguration(config('mcp.servers', []), base_path());
        $snapshot['definition_sources']['mcp_server_definitions'] = 'config:mcp.servers';
    }}
    (new {validator})->validate($snapshot);
    {skeleton}
    $output = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ob_end_clean();
    fwrite(STDOUT, $output);
}} catch (Throwable $error) {{
    ob_end_clean();
    // Database errors may contain bindings; expose only controlled validator text.
    $message = $error instanceof InvalidArgumentException ? $error->getMessage() : get_class($error);
    fwrite(STDERR, 'Registry export rejected: '.$message."\\n");
    exit(1);
}}
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True, help='Configured SSH alias or explicit user@host')
    parser.add_argument('--ssh-config', default=os.environ.get('PLOS_CLI_SYNC_SSH_CONFIG'),
                        help='Explicit host-local SSH configuration; never falls back when unavailable')
    parser.add_argument('--project', default=None)
    parser.add_argument('--php', default='php', help='Local PHP executable used only for source=local')
    parser.add_argument('--manifest', type=Path, default=ROOT / 'config/plos-cli-registry-export.json')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--network', choices=['offline', 'lan', 'online'], default='lan')
    parser.add_argument('--include-schema', action='store_true',
                        help='Include MySQL table definitions and migration history, never operational rows')
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*(?:@[A-Za-z0-9][A-Za-z0-9_.-]*)?', args.source):
        raise SystemExit('Use an SSH alias or explicit user@host, not a shell expression.')
    if args.network == 'offline':
        raise SystemExit('Network mode is offline; no source connection attempted.')
    local = args.source == 'local'
    if local and args.project is not None and Path(args.project).resolve() != ROOT:
        raise SystemExit('Local registry source must be this PLOS installation.')
    if not local and not args.project:
        raise SystemExit('Remote registry sync requires an explicit approved --project path.')
    args.project = str(ROOT) if local else args.project
    ssh = ['ssh']
    if not local and args.ssh_config is not None:
        if not args.ssh_config or '\x00' in args.ssh_config:
            raise SystemExit('Selected SSH configuration is unavailable; previous generation retained.')
        config_path = Path(args.ssh_config).absolute()
        if not config_path.is_file():
            raise SystemExit('Selected SSH configuration is unavailable; previous generation retained.')
        ssh += ['-F', str(config_path)]
    if not local and args.network == 'lan':
        settings = subprocess.run(ssh + ['-G', '--', args.source], check=True,
                                  stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=10).stdout
        config = dict(line.split(' ', 1) for line in settings.splitlines() if ' ' in line)
        if config.get('proxycommand', 'none') != 'none' or config.get('proxyjump', 'none') != 'none':
            raise SystemExit('LAN sync requires a direct configured SSH route.')
        addresses = socket.getaddrinfo(config['hostname'], int(config.get('port', 22)), type=socket.SOCK_STREAM)
        if not addresses or any(not ipaddress.ip_address(row[4][0]).is_private for row in addresses):
            raise SystemExit('Public registry source requires online network mode.')
    if not args.project.startswith('/') or '\x00' in args.project:
        raise SystemExit('A canonical absolute source project path is required.')
    manifest = args.manifest.read_bytes()
    json.loads(manifest)
    output = args.output.absolute()
    if output.exists() or output.is_symlink():
        raise SystemExit('Choose a new snapshot path; existing snapshots are never overwritten.')
    if os.name != 'nt':
        output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        if output.parent.stat().st_mode & 0o077:
            raise SystemExit('Snapshot directory must be private (mode 0700).')
    if os.name == 'nt':
        powershell = Path(os.environ.get('SystemRoot', '')) / 'System32/WindowsPowerShell/v1.0/powershell.exe'
        if not powershell.is_file():
            raise SystemExit('Private Windows snapshot directory verification is unavailable.')
        check = subprocess.run([str(powershell), '-NoProfile', '-NonInteractive', '-File',
                                str(ROOT / 'scripts/plos-cli/state-directory.ps1'),
                                '-PathBase64', encoded(str(output.parent).encode()), '-Create'],
                               stdin=subprocess.DEVNULL, capture_output=True, timeout=20)
        if check.returncode or json.loads(check.stdout).get('private') is not True:
            raise SystemExit('Windows snapshot directory must be private to this operator.')
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=output.parent, prefix='.sync-', delete=False) as staged:
            temporary = Path(staged.name)
            # stdin carries reviewed source, not shell-interpolated PHP or credentials.
            command = [args.php] if local else ssh + ['-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=8',
                                                      '--', args.source, 'php']
            result = subprocess.run(command,
                                    input=export_program(manifest, args.project, args.include_schema).encode(),
                                    stdout=staged, stderr=subprocess.PIPE, timeout=120)
            staged.flush()
            os.fsync(staged.fileno())
        # Windows must close the file before reopening, publishing or unlinking it.
        if result.returncode:
            message = result.stderr.decode('utf-8', 'replace')
            if 'Host key verification failed.' in message or 'REMOTE HOST IDENTIFICATION HAS CHANGED' in message:
                raise SystemExit('Registry SSH host verification failed; configure a trusted host key; previous generation retained.')
            if re.search(r'Permission denied \([^\r\n)]+\)', message):
                raise SystemExit('Registry SSH login unavailable; configure a host-local identity; previous generation retained.')
            if message.startswith('Registry export rejected:'):
                raise SystemExit(message.rstrip())
            raise SystemExit('Registry source unavailable or rejected the read-only export; previous generation retained.')
        original = temporary.read_bytes()
        snapshot = json.loads(original)
        if snapshot.get('schema') != 'plos.cli.registry.v1' or not isinstance(snapshot.get('tables'), dict):
            raise SystemExit('Incomplete registry export; previous generation retained.')
        digest = hashlib.sha256(original).hexdigest()
        # Hardlink publishes a complete file with no overwrite race.
        os.link(temporary, output)
        print(json.dumps({'schema': 'plos.cli.sync-receipt.v1', 'output': str(output),
                          'source': snapshot['source'], 'sha256': digest,
                          'tables': {name: len(rows) for name, rows in snapshot['tables'].items()},
                          'skill_bundles': len(snapshot.get('skill_bundles', [])),
                          'skill_version_mismatches': [b['skill_name'] for b in snapshot.get('skill_bundles', []) if not b['version_matches']],
                          'definition_sources': snapshot.get('definition_sources', {}),
                          'source_writes': 0, 'activation_performed': False}))
    except subprocess.TimeoutExpired:
        raise SystemExit('Registry source timed out; previous generation retained.') from None
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    main()
