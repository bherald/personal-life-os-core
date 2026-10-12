<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Local Windows observations only; never execution permission or ACL repair. */
final class OsToolWindowsEnvironment
{
    private const MAX_SELECTED_FILES = 32;

    // WriteData, AppendData, WriteEA, DeleteChild, WriteAttributes, Delete,
    // WriteDACL and WriteOwner. Includes inherited writes to descendants.
    private const WRITE_RIGHTS = 0x000D0156;

    // Outside the selected tree, CreateDirectories alone cannot replace an
    // existing child. Keep DeleteChild/DELETE/DACL/owner/data/EA/attribute checks.
    private const ANCESTOR_RIGHTS = 0x000D0152;

    public static function observe(string $root): array
    {
        return self::observeSelected($root)['execution_environment'];
    }

    /** Paths come from a reviewed local definition/manifest, never a tool argument. */
    public static function observeSelected(string $root, array $files = []): array
    {
        $unknown = ['schema' => 'plos.os-tools.execution-environment.v1', 'platform' => 'Windows',
            'architecture' => php_uname('m'), 'status' => 'unsupported_or_unavailable', 'host_fingerprint' => null,
            'fingerprint' => null, 'basis' => 'local_hashed_windows_identity_and_acl_ancestry_v3',
            'remote_authentication' => 'provided_by_existing_execution_route_not_this_observation'];
        $system = getenv('SystemRoot');
        $powershell = is_string($system) ? $system.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : '';
        $script = dirname(__DIR__, 2).'/scripts/plos-cli/windows-execution-environment.ps1';
        try {
            self::selection($files);
            if (PHP_OS_FAMILY !== 'Windows' || ! is_file($powershell) || ! is_file($script)) {
                throw new RuntimeException('Windows local collector unavailable.');
            }
            $process = new Process([$powershell, '-NoProfile', '-NonInteractive', '-File', $script,
                '-RootBase64', base64_encode($root), '-SelectionBase64', base64_encode(json_encode($files, JSON_THROW_ON_ERROR))], timeout: 20);
            $process->run();
            if (! $process->isSuccessful() || strlen($process->getOutput()) > 1048576) {
                throw new RuntimeException('Windows local collector did not finish within bounds.');
            }

            return self::verifiedSelection(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), $root, php_uname('m'), $files);
        } catch (\Throwable) {
            return ['execution_environment' => $unknown, 'selected_files' => [], 'complete' => false,
                'execution_authorized' => false, 'reason' => 'local_identity_or_selected_evidence_unavailable'];
        }
    }

    public static function verified(array $observation, string $expectedRoot, string $architecture): array
    {
        return self::verifiedSelection($observation, $expectedRoot, $architecture, [])['execution_environment'];
    }

    /** Only the fixed local helper supplies this record. No model-visible input route. */
    public static function verifiedSelection(array $observation, string $expectedRoot, string $architecture, array $files): array
    {
        self::selection($files);
        if (array_diff(array_keys($observation), ['schema', 'root', 'machine_id_sha256', 'hostname_sha256',
            'account_sid_sha256', 'acl_paths', 'selected_files']) !== []
            || ($observation['schema'] ?? null) !== 'plos.os-tools.windows-environment.v3'
            || self::path($observation['root'] ?? null) !== self::path($expectedRoot)) {
            throw new RuntimeException('Windows local identity response differs from its execution root.');
        }
        foreach (['machine_id_sha256', 'hostname_sha256', 'account_sid_sha256'] as $field) {
            self::sha($observation[$field] ?? null);
        }
        $acls = $observation['acl_paths'] ?? null;
        if (! is_array($acls) || count($acls) < 2 || count($acls) > 256 || ! array_is_list($acls)) {
            throw new RuntimeException('Bounded ACL ancestry required.');
        }
        $aclMap = [];
        $selectedRoot = self::path($expectedRoot);
        $selectedPaths = array_map(fn ($file) => self::path($file['path']), $files);
        foreach ($acls as $acl) {
            if (! is_array($acl) || array_diff(array_keys($acl), ['path', 'owner_trusted', 'owner_windows_servicing', 'reparse', 'sha256', 'rules']) !== []
                || ! is_bool($acl['owner_trusted'] ?? null) || ! is_bool($acl['owner_windows_servicing'] ?? null) || ($acl['reparse'] ?? null) !== false
                || ! is_array($acl['rules'] ?? null) || count($acl['rules']) < 1 || count($acl['rules']) > 256) {
                throw new RuntimeException('Selected path ownership/ACL is unavailable or unsafe.');
            }
            $path = self::path($acl['path'] ?? null);
            $selected = $path === $selectedRoot || str_starts_with($path, $selectedRoot.'\\') || in_array($path, $selectedPaths, true);
            // Only the actual locally resolved Windows servicing owner can
            // qualify an outside ancestor; selected roots/files stay strict.
            if (! $acl['owner_trusted'] && ($selected || ! $acl['owner_windows_servicing'])) {
                throw new RuntimeException('Selected path or ancestor owner is not trusted.');
            }
            if (isset($aclMap[$path])) {
                throw new RuntimeException('Duplicate ACL identity refused.');
            }
            self::sha($acl['sha256'] ?? null);
            foreach ($acl['rules'] as $rule) {
                if (! is_array($rule) || count($rule) !== 4 || array_diff(array_keys($rule), ['allow', 'trusted', 'rights', 'inherit_only']) !== []
                    || ! is_bool($rule['allow'] ?? null) || ! is_bool($rule['trusted'] ?? null)
                    || ! is_bool($rule['inherit_only'] ?? null) || ! is_int($rule['rights'] ?? null)
                    || $rule['rights'] < -2147483648 || $rule['rights'] > 0xFFFFFFFF) {
                    throw new RuntimeException('Malformed ACL evidence refused.');
                }
                // Conservatively reject a dangerous allow even when another ACE
                // might deny it; do not implement a partial Windows access evaluator.
                $rights = self::mappedRights($rule['rights']);
                if (! $selected && $rule['inherit_only']) {
                    // It has no effect on this ancestor. Every actual selected
                    // child is still checked using its own complete ACL.
                    continue;
                }
                $mask = $selected ? self::WRITE_RIGHTS : self::ANCESTOR_RIGHTS;
                if ($rule['allow'] && ! $rule['trusted'] && ($rights & $mask)) {
                    throw new RuntimeException('Untrusted replacement or inherited write rights refused.');
                }
            }
            $aclMap[$path] = $acl['sha256'];
        }
        $required = [self::path($expectedRoot)];
        $selected = $observation['selected_files'] ?? null;
        if (! is_array($selected) || ! array_is_list($selected) || count($selected) !== count($files)) {
            throw new RuntimeException('Selected evidence differs from the reviewed file list.');
        }
        $identity = [];
        foreach ($files as $index => $file) {
            $record = $selected[$index];
            if (! is_array($record) || array_diff(array_keys($record), ['path', 'kind', 'sha256', 'bytes', 'text']) !== []
                || self::path($record['path'] ?? null) !== self::path($file['path']) || ($record['kind'] ?? null) !== $file['kind']
                || ! is_int($record['bytes'] ?? null) || $record['bytes'] < 0 || $record['bytes'] > ($file['kind'] === 'document' ? 262144 : 33554432)) {
                throw new RuntimeException('Selected file is missing, oversized or mismatched.');
            }
            self::sha($record['sha256'] ?? null);
            if ($file['kind'] === 'document') {
                if (! is_string($record['text'] ?? null) || strlen($record['text']) !== $record['bytes']
                    || ! hash_equals($record['sha256'], hash('sha256', $record['text']))) {
                    throw new RuntimeException('Documentation must be complete exact UTF-8 evidence.');
                }
            } elseif (array_key_exists('text', $record)) {
                throw new RuntimeException('Executable bytes may not be copied into evidence.');
            }
            $required[] = self::path($record['path']);
            $identity[] = ['path_sha256' => hash('sha256', self::path($record['path'])), 'kind' => $record['kind'],
                'sha256' => $record['sha256'], 'bytes' => $record['bytes']];
        }
        $covered = [];
        foreach ($required as $path) {
            while (true) {
                if (! isset($aclMap[$path])) {
                    throw new RuntimeException('A selected path or replacement ancestor was not inspected.');
                }
                $covered[$path] = $aclMap[$path];
                if (strlen($path) === 3) {
                    break;
                }
                $path = substr($path, 0, strrpos($path, '\\'));
                if (strlen($path) === 2) {
                    $path .= '\\';
                }
            }
        }
        if (count($covered) !== count($aclMap)) {
            throw new RuntimeException('Unexpected unrelated ACL records refused.');
        }
        ksort($covered);
        $host = ['platform' => 'Windows', 'architecture' => $architecture,
            'machine_id_sha256' => $observation['machine_id_sha256'], 'hostname_sha256' => $observation['hostname_sha256']];
        $scope = ['acl_policy' => 'selected-full-ancestor-effective-v3', 'host' => $host, 'root' => self::path($expectedRoot), 'account_sid_sha256' => $observation['account_sid_sha256'],
            'acl_ancestry' => $covered, 'selected_files' => $identity];
        $environment = ['schema' => 'plos.os-tools.execution-environment.v1', 'platform' => 'Windows', 'architecture' => $architecture,
            'status' => 'observed', 'host_fingerprint' => hash('sha256', json_encode($host, JSON_THROW_ON_ERROR)),
            'fingerprint' => hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR)),
            'basis' => 'local_hashed_windows_identity_and_acl_ancestry_v3',
            'remote_authentication' => 'provided_by_existing_execution_route_not_this_observation'];

        return ['execution_environment' => $environment, 'selected_files' => $identity,
            'documentation' => array_values(array_map(fn ($record) => ['path_sha256' => hash('sha256', self::path($record['path'])),
                'sha256' => $record['sha256'], 'text' => $record['text'], 'trust' => 'untrusted_documentation',
                'license_review' => 'human_review_required', 'package_ownership' => 'not_verified_no_windows_package_manager_claim'],
                array_filter($selected, fn ($record) => $record['kind'] === 'document'))),
            'complete' => true, 'execution_authorized' => false];
    }

    /** Map documented generic rights without pretending to evaluate deny/group ACLs. */
    private static function mappedRights(int $rights): int
    {
        $mask = $rights & 0xFFFFFFFF;
        // Valid file-specific/standard, ACCESS_SYSTEM_SECURITY, MAXIMUM_ALLOWED,
        // and generic bits only; unknown reserved bits fail closed.
        if (($mask & ~0xF31FFFFF) !== 0) {
            throw new RuntimeException('Unknown Windows ACL access bits refused.');
        }
        if ($mask & 0x02000000) {
            throw new RuntimeException('MAXIMUM_ALLOWED is not a concrete ACL permission.');
        }
        $mapped = $mask & 0x01FFFFFF;
        if ($mask & 0x10000000) {
            $mapped |= 0x001F01FF;
        }
        if ($mask & 0x40000000) {
            $mapped |= 0x00120116;
        }
        if ($mask & 0x80000000) {
            $mapped |= 0x00120089;
        }
        if ($mask & 0x20000000) {
            $mapped |= 0x001200A0;
        }

        return $mapped;
    }

    private static function selection(array $files): void
    {
        if (! array_is_list($files) || count($files) > self::MAX_SELECTED_FILES) {
            throw new RuntimeException('Selected Windows file list exceeds bounds.');
        }
        $seen = [];
        foreach ($files as $file) {
            if (! is_array($file) || count($file) !== 2 || array_diff(array_keys($file), ['path', 'kind']) !== []
                || ! in_array($file['kind'] ?? null, ['executable', 'code', 'document'], true)) {
                throw new RuntimeException('Only reviewed executable/code/document paths may be inspected.');
            }
            $path = self::path($file['path'] ?? null);
            if (strlen($path) === 3 || isset($seen[$path])) {
                throw new RuntimeException('Selected Windows files must be distinct regular paths.');
            }
            $seen[$path] = true;
        }
    }

    private static function path(mixed $path): string
    {
        if (! is_string($path) || strlen($path) > 4096 || ! preg_match('~^[A-Za-z]:[\\\\/]~D', $path)
            || preg_match('~[\x00-\x1f\x7f<>"|?*]~', $path) || str_contains(substr($path, 2), ':')) {
            throw new RuntimeException('Only explicit drive-local Windows paths are accepted.');
        }
        $path = str_replace('/', '\\', $path);
        if (strlen($path) > 3) {
            foreach (explode('\\', substr($path, 3)) as $part) {
                if ($part === '' || $part === '.' || $part === '..' || preg_match('/[ .]$/D', $part)) {
                    throw new RuntimeException('Ambiguous Windows path refused.');
                }
            }
        }

        return strtolower($path);
    }

    private static function sha(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new RuntimeException('Windows identity fingerprint missing.');
        }
    }
}
