<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Execution bindings are configuration rows, independent of engines and models. */
final class CliFrameworkServicePolicy
{
    public static function validate(array $policy): void
    {
        if (array_diff(array_keys($policy), ['targets', 'tools', 'worker_receipts']) !== []
            || ! is_array($policy['targets'] ?? null) || ! is_array($policy['tools'] ?? null)) {
            throw new RuntimeException('Framework services require targets and tool execution rules.');
        }
        if (isset($policy['worker_receipts'])) {
            $journal = $policy['worker_receipts'];
            if (! is_array($journal) || count($journal) < 4 || count($journal) > 5
                || array_diff(['path', 'session_id', 'turn_id', 'worker_id'], array_keys($journal)) !== []
                || array_diff(array_keys($journal), ['path', 'session_id', 'turn_id', 'worker_id', 'supervisor']) !== []) {
                throw new RuntimeException('Worker receipts require one exact private journal binding.');
            }
            foreach (array_intersect_key($journal, array_flip(['path', 'session_id', 'turn_id', 'worker_id'])) as $value) {
                if (! is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('/[\\x00-\\x1f\\x7f]/', $value)) {
                    throw new RuntimeException('Invalid worker journal identity.');
                }
            }
            if (! str_starts_with($journal['path'], '/') && ! self::windowsPath($journal['path'])) {
                throw new RuntimeException('Worker journal must use an operator-configured absolute path.');
            }
            if (isset($journal['supervisor'])) {
                $supervisor = $journal['supervisor'];
                if (! is_array($supervisor) || array_diff(array_keys($supervisor), ['helper', 'runtime_files', 'memory_mb']) !== []
                    || ! self::windowsPath($supervisor['helper'] ?? null, executable: true)
                    || ! is_array($supervisor['runtime_files'] ?? null) || count($supervisor['runtime_files']) !== 3
                    || (isset($supervisor['memory_mb']) && (! is_int($supervisor['memory_mb']) || $supervisor['memory_mb'] < 1 || $supervisor['memory_mb'] > 65536))) {
                    throw new RuntimeException('Worker supervisor requires three exact private Windows binaries and optional memory bound.');
                }
                $directory = dirname(str_replace('\\', '/', $supervisor['helper']));
                $pins = [];
                foreach ($supervisor['runtime_files'] as $path => $hash) {
                    if (! self::windowsPath($path, executable: true) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                        throw new RuntimeException('Invalid Windows supervisor binary pin.');
                    }
                    $pins[str_replace('\\', '/', $path)] = $hash;
                }
                foreach (['plos-windows-sandbox.exe', 'codex-command-runner.exe', 'codex-windows-sandbox-setup.exe'] as $name) {
                    if (! isset($pins[$directory.'/'.$name])) {
                        throw new RuntimeException('Complete existing Windows supervisor binary closure is required.');
                    }
                }
                if (str_replace('\\', '/', $supervisor['helper']) !== $directory.'/plos-windows-sandbox.exe') {
                    throw new RuntimeException('Only the fixed Windows supervisor entrypoint is permitted.');
                }
            }

        }
        foreach ($policy['targets'] as $name => $target) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name)
                || ! is_array($target) || array_diff(array_keys($target), ['transport', 'host', 'root', 'production', 'platform', 'php', 'php_ini', 'runtime_files']) !== []
                || ! in_array($target['transport'] ?? null, ['local', 'ssh'], true)) {
                throw new RuntimeException('Invalid framework service target.');
            }
            if ($target['transport'] === 'ssh') {
                $windows = ($target['platform'] ?? null) === 'windows';
                if (array_key_exists('platform', $target) && ! $windows) {
                    throw new RuntimeException('Only an explicit Windows platform extension is supported.');
                }
                if (($windows && (! self::windowsPath($target['root'] ?? null) || ! self::windowsPath($target['php'] ?? null, executable: true)))
                    || (! $windows && array_key_exists('php', $target))) {
                    throw new RuntimeException('Windows SSH requires exact local root and PHP executable bindings.');
                }
                if (array_key_exists('php_ini', $target) || array_key_exists('runtime_files', $target)) {
                    $runtime = $target['runtime_files'] ?? null;
                    if (! $windows || ! self::windowsPath($target['php_ini'] ?? null)
                        || strtolower(pathinfo($target['php_ini'], PATHINFO_EXTENSION)) !== 'ini'
                        || ! is_array($runtime) || count($runtime) < 3 || count($runtime) > 64
                        || ! isset($runtime[$target['php']], $runtime[$target['php_ini']])) {
                        throw new RuntimeException('Private Windows PHP configuration requires exact executable, ini and dependency content pins.');
                    }
                    foreach ($runtime as $path => $sha) {
                        if (! self::windowsPath($path) || ! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                            throw new RuntimeException('Invalid private Windows PHP dependency pin.');
                        }
                    }
                }
                if (! is_bool($target['production'] ?? null)
                    || ! is_string($target['root'] ?? null) || (! $windows && ! str_starts_with($target['root'], '/'))
                    || preg_match('/[\x00-\x1f\x7f]/', $target['root'])
                    || ! is_string($target['host'] ?? null)
                    || ! preg_match('/^(?:[A-Za-z0-9_][A-Za-z0-9_.-]*@)?[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $target['host'])) {
                    throw new RuntimeException('Framework SSH requires an exact host or existing SSH alias.');
                }
            } elseif ($name !== 'local' || count($target) !== 1) {
                throw new RuntimeException('Local framework execution uses the current PLOS installation.');
            }
        }
        foreach ($policy['tools'] as $name => $rule) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $name)
                || ! is_array($rule) || array_diff(array_keys($rule), ['target', 'tree_scoped', 'tree_argument', 'references', 'file_inputs', 'null_result_error', 'durable_receipts', 'supervised_process', 'timeout_ms']) !== []
                || ! in_array($rule['target'] ?? null, ['workflow', 'local'], true)
                || ! is_bool($rule['tree_scoped'] ?? null) || ! is_array($rule['references'] ?? null)) {
                throw new RuntimeException('Invalid framework tool execution rule.');
            }
            if (array_key_exists('timeout_ms', $rule)
                && (! is_int($rule['timeout_ms']) || $rule['timeout_ms'] < 50 || $rule['timeout_ms'] > 3600000)) {
                throw new RuntimeException('Framework tool timeout must be 50 through 3600000 milliseconds.');
            }
            if (array_key_exists('durable_receipts', $rule) && ! is_bool($rule['durable_receipts'])) {
                throw new RuntimeException('Durable receipt opt-in must be a boolean.');
            }
            if (array_key_exists('supervised_process', $rule)
                && (! is_bool($rule['supervised_process']) || ($rule['supervised_process']
                    && (($rule['durable_receipts'] ?? false) !== true || ! isset($policy['worker_receipts']['supervisor']))))) {
                throw new RuntimeException('Supervised process opt-in requires a durable rule and reviewed supervisor.');
            }
            if (array_key_exists('null_result_error', $rule) && (! is_string($rule['null_result_error'])
                || trim($rule['null_result_error']) === '' || strlen($rule['null_result_error']) > 1000
                || preg_match('/[\x00-\x1f\x7f]/', $rule['null_result_error']))) {
                throw new RuntimeException('Null-result policy requires a readable failure message.');
            }
            if (array_key_exists('file_inputs', $rule)) {
                if (! is_array($rule['file_inputs']) || ! array_is_list($rule['file_inputs'])) {
                    throw new RuntimeException('File inputs must list registered argument paths.');
                }
                foreach ($rule['file_inputs'] as $argument) {
                    if (! self::argumentPath($argument)) {
                        throw new RuntimeException('Invalid file-input argument path.');
                    }
                }
            }
            if (isset($rule['tree_argument']) && (! $rule['tree_scoped'] || ! self::argumentName($rule['tree_argument']))) {
                throw new RuntimeException('Tree argument requires a tree-scoped tool rule.');
            }
            foreach ($rule['references'] as $argument => $reference) {
                if (! $rule['tree_scoped'] || ! self::argumentPath($argument) || ! is_array($reference)
                    || array_diff(array_keys($reference), ['table', 'id_column', 'tree_column']) !== []
                    || ! self::identifier($reference['table'] ?? null) || ! self::identifier($reference['id_column'] ?? null)
                    || ! self::identifier($reference['tree_column'] ?? null)) {
                    throw new RuntimeException('Invalid table-owned tree reference rule.');
                }
            }
            if ($rule['tree_scoped'] && ! isset($rule['tree_argument']) && $rule['references'] === []) {
                throw new RuntimeException('Tree-scoped service execution requires an explicit tree argument or record reference.');
            }
        }
    }

    private static function windowsPath(mixed $path, bool $executable = false): bool
    {
        if (! is_string($path) || ! preg_match('~^[A-Za-z]:[\\\\/]~', $path)
            || strlen($path) <= 3 || preg_match('/[\x00-\x1f\x7f<>"|?*]/', $path)
            || str_contains(substr($path, 2), ':')) {
            return false;
        }
        $parts = preg_split('~[\\\\/]~', substr($path, 3));
        if (array_filter($parts, fn ($part) => $part === '' || $part === '.' || $part === '..' || rtrim($part, ' .') !== $part)) {
            return false;
        }

        return ! $executable || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'exe';
    }

    public static function fingerprint(array $definition): string
    {
        $fields = ['service', 'method', 'parameters', 'permissions', 'risk_level', 'requires_confirmation',
            'mcp_server', 'mcp_tool', 'cli_parameter_bundles'];
        $contract = [];
        foreach ($fields as $field) {
            $contract[$field] = $definition[$field] ?? null;
        }

        return hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function argumentPath(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        foreach (explode('.', $value) as $part) {
            if (! self::argumentName($part)) {
                return false;
            }
        }

        return true;
    }

    private static function argumentName(mixed $value): bool
    {
        // PLOS registry parameters use both snake_case and camelCase. These
        // names index argument objects; SQL identifiers stay separately strict.
        return is_string($value) && preg_match('/^[a-z][a-zA-Z0-9_]*$/D', $value) === 1;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]*$/D', $value) === 1;
    }
}
