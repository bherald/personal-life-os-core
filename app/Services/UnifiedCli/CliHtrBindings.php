<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** Private, exact local CPU runtime bindings; table locality is never authority. */
final class CliHtrBindings
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $manifest,
    ) {}

    public function inspect(): array
    {
        try {
            $selected = $this->select();

            return ['configured' => true, 'compute_instance' => $selected['compute_instance'],
                'binding_sha256' => $selected['binding_sha256'], 'device' => 'cpu', 'runtime_admission_checked' => false];
        } catch (\Throwable) {
            return ['configured' => false, 'runtime_admission_checked' => false,
                'reason' => 'No valid local HTR binding matches the selected compute definition on this computer.'];
        }
    }

    public function select(): array
    {
        $bytes = CliLocalBindings::readPrivateFile($this->manifest);
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['schema'] ?? null) !== 'plos.cli.htr-bindings.v1'
            || array_diff(array_keys($manifest), ['schema', 'runtimes']) !== [] || ! is_array($manifest['runtimes'] ?? null)) {
            throw new RuntimeException('Invalid local HTR binding manifest.');
        }
        $session = $this->store->session($this->sessionId);
        $configuration = new CliConfiguration($this->store);
        $discovery = (new CliComputeSelection($configuration))->inspect($session, 'htr');
        foreach ($discovery['candidates'] as $candidate) {
            $id = $candidate['instance_id'];
            if (! $candidate['configuration_eligible'] || ! isset($manifest['runtimes'][$id])) {
                continue;
            }
            $binding = $manifest['runtimes'][$id];
            $this->validate($binding);
            // Automatic discovery may pass unbound/nonlocal entries, never run
            // an unselected remote host or retry a failed worker elsewhere.
            if (PHP_OS_FAMILY !== 'Linux' || ! hash_equals($binding['machine_id_sha256'], hash_file('sha256', '/etc/machine-id'))) {
                if ($discovery['mode'] === 'manual') {
                    throw new RuntimeException('Selected HTR runtime belongs to a different execution computer.');
                }

                continue;
            }
            $rows = array_values(array_filter($configuration->definitions($session, 'compute_instances'),
                fn ($row) => ($row['instance_id'] ?? null) === $id));
            $row = $rows[0];
            if (($row['host'] ?? null) !== $binding['registry_host'] || ($row['python_path'] ?? null) !== $binding['python_path']) {
                throw new RuntimeException('HTR binding differs from the current compute host or Python definition.');
            }

            return ['compute_instance' => $id, 'binding_sha256' => hash('sha256', $bytes),
                'registry_sha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), 'runtime' => $binding];
        }
        throw new RuntimeException('No selected HTR capability has an exact local CPU binding. Use /compute and install the intended runtime binding on its execution computer.');
    }

    /** All checks are local reads; this does not grant GPU or general agent authority. */
    public function verify(array $selected, ?Closure $keeper = null): void
    {
        $keeper?->__invoke();
        if ($this->select() !== $selected) {
            throw new RuntimeException('HTR binding or compute selection changed before execution.');
        }
        $binding = $selected['runtime'];
        $this->file($binding['worker_path'], $binding['worker_sha256'], $keeper);
        $python = realpath($binding['python_path']);
        if ($python === false || ! is_executable($python)) {
            throw new RuntimeException('Bound HTR Python is unavailable.');
        }
        $this->file($python, $binding['python_sha256'], $keeper);
        foreach (['runtime_root', 'site_packages'] as $key) {
            if (realpath($binding[$key]) !== $binding[$key] || ! is_dir($binding[$key])) {
                throw new RuntimeException('Bound HTR runtime directory changed or is unavailable.');
            }
        }
        if ($binding['runtime_root'] !== '/usr' && ! is_file($binding['runtime_root'].'/pyvenv.cfg')) {
            throw new RuntimeException('HTR runtime root must be the system Python prefix or an existing virtual environment.');
        }
        foreach ($binding['model_files'] as $file) {
            $this->file($file['path'], $file['sha256'], $keeper);
        }
        $generation = json_decode(file_get_contents($binding['model_files']['generation_config.json']['path']), true, flags: JSON_THROW_ON_ERROR);
        $eos = $generation['eos_token_id'] ?? null;
        if ((is_int($eos) ? [$eos] : $eos) !== $binding['eos_token_ids']) {
            throw new RuntimeException('Bound HTR EOS tokens differ from the exact generation configuration.');
        }
        foreach ($binding['maintenance_markers'] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('A bound maintenance gate is active; HTR was not admitted.');
            }
        }
        $memory = file_get_contents('/proc/meminfo');
        if (! preg_match('/^MemAvailable:\s+(\d+) kB$/m', $memory, $match)
            || (int) $match[1] < $binding['minimum_available_memory_mb'] * 1024) {
            throw new RuntimeException('Available physical memory is below the bound HTR floor.');
        }
    }

    private function file(string $path, string $hash, ?Closure $keeper): void
    {
        if (realpath($path) !== $path || ! is_file($path)) {
            throw new RuntimeException('Bound HTR asset identity changed or is unavailable.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Bound HTR asset cannot be read.');
        }
        try {
            $context = hash_init('sha256');
            while (! feof($stream)) {
                $keeper?->__invoke();
                $bytes = fread($stream, 1048576);
                if ($bytes === false) {
                    throw new RuntimeException('Bound HTR asset read failed.');
                }
                hash_update($context, $bytes);
            }
            $keeper?->__invoke();
            if (! hash_equals($hash, hash_final($context))) {
                throw new RuntimeException('Bound HTR asset identity changed or is unavailable.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function validate(mixed $binding): void
    {
        $keys = ['registry_host', 'machine_id_sha256', 'python_path', 'python_sha256', 'runtime_root', 'site_packages',
            'worker_path', 'worker_sha256', 'model_files', 'eos_token_ids', 'device', 'sandbox',
            'minimum_available_memory_mb', 'timeout_seconds', 'maintenance_markers', 'required_services'];
        if (! is_array($binding) || count($binding) !== count($keys) || array_diff(array_keys($binding), $keys) !== []
            || ($binding['device'] ?? null) !== 'cpu' || ! in_array($binding['sandbox'] ?? null, ['user', 'sudo_drop'], true)) {
            throw new RuntimeException('HTR requires an exact CPU binding with an explicit sandbox; no device fallback is available.');
        }
        foreach (['machine_id_sha256', 'python_sha256', 'worker_sha256'] as $key) {
            if (! is_string($binding[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', $binding[$key])) {
                throw new RuntimeException('HTR binding requires exact SHA-256 identities.');
            }
        }
        foreach (['python_path', 'runtime_root', 'site_packages', 'worker_path'] as $key) {
            $this->path($binding[$key]);
        }
        if (! is_string($binding['registry_host']) || $binding['registry_host'] === ''
            || ! in_array($binding['python_path'], [$binding['runtime_root'].'/bin/python', $binding['runtime_root'].'/bin/python3'], true)
            || ! in_array(basename($binding['site_packages']), ['site-packages', 'dist-packages'], true)) {
            throw new RuntimeException('Invalid HTR host or Python runtime layout.');
        }
        if (! is_int($binding['minimum_available_memory_mb']) || $binding['minimum_available_memory_mb'] < 1
            || ! is_int($binding['timeout_seconds']) || $binding['timeout_seconds'] < 1 || $binding['timeout_seconds'] > 3600
            || ! is_array($binding['maintenance_markers']) || ! array_is_list($binding['maintenance_markers'])
            || ! is_array($binding['required_services']) || ! array_is_list($binding['required_services'])
            || ! is_array($binding['eos_token_ids']) || ! array_is_list($binding['eos_token_ids']) || $binding['eos_token_ids'] === []) {
            throw new RuntimeException('Invalid HTR resource, maintenance or EOS contract.');
        }
        foreach ($binding['maintenance_markers'] as $path) {
            $this->path($path);
        }
        foreach ($binding['required_services'] as $unit) {
            if (! is_string($unit) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]*$/D', $unit)) {
                throw new RuntimeException('Invalid HTR service health requirement.');
            }
        }
        foreach ($binding['eos_token_ids'] as $token) {
            if (! is_int($token) || $token < 0) {
                throw new RuntimeException('Invalid HTR EOS token identity.');
            }
        }
        if (! is_array($binding['model_files']) || ! isset($binding['model_files']['model.safetensors'], $binding['model_files']['generation_config.json'])) {
            throw new RuntimeException('HTR requires exact local Safetensors and generation assets.');
        }
        foreach ($binding['model_files'] as $name => $file) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(json|txt|safetensors)$/D', $name)
                || ! is_array($file) || count($file) !== 2 || ! isset($file['path'], $file['sha256'])
                || ! is_string($file['sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new RuntimeException('Invalid HTR model asset map.');
            }
            $this->path($file['path']);
        }
    }

    private function path(mixed $path): void
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || $path === '/' || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//~', $path) || str_ends_with($path, '/')) {
            throw new RuntimeException('HTR binding paths must be explicit absolute Linux paths.');
        }
    }
}
