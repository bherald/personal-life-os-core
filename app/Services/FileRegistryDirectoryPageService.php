<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Native, independently qualified directory pages; no identity or catalog writes. */
class FileRegistryDirectoryPageService
{
    public function __construct(private SystemConfigService $systemConfig) {}

    public function observe(string $scope): array
    {
        return $this->page($scope, $scope, ['cookie' => '0', 'generation' => null], null);
    }

    public function qualification(array $observation): ?array
    {
        $fingerprint = $observation['qualification']['runtime_fingerprint'] ?? null;
        $receipts = $this->systemConfig->get('files.registry_discovery_qualifications', []);
        if (is_string($receipts)) {
            $receipts = json_decode($receipts, true);
        }
        $receipt = is_string($fingerprint) && is_array($receipts) ? ($receipts[$fingerprint] ?? null) : null;

        return is_array($receipt) && $this->validReceipt($receipt)
            && $this->matchesReceipt($observation['qualification'] ?? null, $receipt) ? $receipt : null;
    }

    /** Run generated fixtures outside synced originals, then record the exact runtime proof. */
    public function qualify(string $scope, ?string $fixtureParent = null): array
    {
        $observation = $this->observe($scope);
        if (($observation['reason'] ?? null) !== 'qualification_required_or_mismatched') {
            return ['state' => 'held', 'reason' => $observation['reason'] ?? 'runtime_observation_unavailable'];
        }
        try {
            $parent = $this->fixtureParent($fixtureParent);
            $result = $this->run([
                $this->python(), base_path(config('file_registry.discovery.qualification_script')),
                '--fixture-parent', $parent,
            ], '', (float) config('file_registry.discovery.qualification_timeout_seconds'), $this->setting('qualification_max_output_bytes'));
            $receipt = $result['qualification'] ?? null;
            $observed = $observation['qualification'];
            if (! is_array($receipt) || ! $this->validReceipt($receipt)
                || ($receipt['runtime_fingerprint'] ?? null) !== $observed['runtime_fingerprint']
                || ($receipt['helper_sha256'] ?? null) !== $observed['helper_sha256']
                || ($receipt['filesystem'] ?? null) !== $observed['filesystem']
                || ($result['tests'] ?? 0) < 1) {
                throw new RuntimeException('Generated fixtures did not qualify this exact source runtime.');
            }
            // Successful fixtures alone are insufficient if the target mount changed meanwhile.
            $current = $this->observe($scope);
            if (($current['qualification'] ?? null) !== $observed) {
                throw new RuntimeException('Source runtime changed during qualification.');
            }
            $validated = $this->page($scope, $scope, ['cookie' => '0', 'generation' => null], $receipt);
            if (($validated['state'] ?? null) !== 'available') {
                throw new RuntimeException('Successful fixture receipt did not admit the target directory.');
            }
            DB::transaction(function () use ($receipt): void {
                // Serialize receipt-map updates; never overwrite another runtime's evidence.
                $row = DB::table('system_configs')->where('section', 'files')
                    ->where('config_key', 'registry_discovery_qualifications')->lockForUpdate()->first();
                if (! $row) {
                    throw new RuntimeException('Discovery qualification registration is unavailable.');
                }
                $receipts = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($receipts) || count($receipts) >= $this->setting('max_qualification_receipts') && ! isset($receipts[$receipt['runtime_fingerprint']])) {
                    throw new RuntimeException('Qualification receipt capacity requires reviewed pruning.');
                }
                $receipts[$receipt['runtime_fingerprint']] = $receipt;
                $this->systemConfig->set('files.registry_discovery_qualifications', $receipts, 'json');
            });

            return ['state' => 'qualified', 'qualification' => $receipt, 'tests' => $result['tests'],
                'original_photo_writes' => 0, 'catalog_writes' => 0];
        } catch (\Throwable $e) {
            return ['state' => 'held', 'reason' => 'runtime_qualification_unavailable'];
        }
    }

    public function page(string $scope, string $directory, array $checkpoint, ?array $qualification, array $limits = []): array
    {
        try {
            $physical = $this->physicalScope($scope, $directory);
            $policy = $this->policy();
            $defaults = [
                'max_records' => $policy['max_directory_records'],
                'max_entries' => $policy['max_page_entries'],
                'max_output_bytes' => $policy['max_output_bytes'],
                'max_seconds' => $policy['max_page_seconds'],
            ];
            if (array_diff(array_keys($limits), array_keys($defaults)) !== []) {
                throw new RuntimeException('Unknown directory limit.');
            }
            foreach ($defaults as $key => $ceiling) {
                $value = $limits[$key] ?? $ceiling;
                if ($key === 'max_seconds' ? ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0)
                    : (! is_int($value) || $value < 1)) {
                    throw new RuntimeException('Invalid directory limit.');
                }
                $limits[$key] = min($value, $ceiling);
            }
            $payload = ['root' => $physical['root'], 'directory' => $physical['directory'],
                'cookie' => $checkpoint['cookie'] ?? null, 'expected_generation' => $checkpoint['generation'] ?? null,
                'qualification' => $qualification, 'limits' => $limits];
            $seconds = (float) $limits['max_seconds'];
            $result = $this->run([$this->python(), base_path(config('file_registry.discovery.directory_helper'))],
                json_encode($payload, JSON_THROW_ON_ERROR), $seconds + 1,
                $limits['max_output_bytes'] + 1);
            if (! in_array($result['state'] ?? null, ['available', 'held'], true)
                || ! is_array($result['entries'] ?? null) || ! is_bool($result['complete'] ?? null)) {
                throw new RuntimeException('Malformed native directory response.');
            }
            $current = $this->physicalScope($scope, $directory);
            if ($current !== $physical) {
                throw new RuntimeException('Source directory changed during the native response.');
            }
            if ($result['state'] === 'held') {
                if ($result['entries'] !== [] || $result['complete']) {
                    throw new RuntimeException('Held native response cannot contain discovered entries.');
                }

                return $result;
            }
            if ($qualification === null || ! $this->validReceipt($qualification)
                || ! $this->matchesReceipt($result['qualification'] ?? null, $qualification)
                || ($result['start_cookie'] ?? null) !== $checkpoint['cookie']
                || ! $this->validCookie($result['cookie'] ?? null)
                || ! array_is_list($result['entries']) || count($result['entries']) > $limits['max_entries']
                || ! is_int($result['records'] ?? null) || $result['records'] < count($result['entries']) || $result['records'] > $limits['max_records']) {
                throw new RuntimeException('Unproven or excessive native directory response.');
            }
            $generation = $result['generation'] ?? null;
            if (! is_array($generation) || count($generation) !== 4) {
                throw new RuntimeException('Missing current directory generation.');
            }
            foreach (['dev', 'ino', 'mtime_ns', 'ctime_ns'] as $key) {
                if (! is_int($generation[$key] ?? null) || $generation[$key] < 0) {
                    throw new RuntimeException('Invalid current directory generation.');
                }
            }
            if ($generation['dev'] !== $physical['directory_proof']['dev'] || $generation['ino'] !== $physical['directory_proof']['ino']
                || intdiv($generation['mtime_ns'], 1000000000) !== $physical['directory_proof']['mtime']
                || intdiv($generation['ctime_ns'], 1000000000) !== $physical['directory_proof']['ctime']) {
                throw new RuntimeException('Native directory generation does not match the current path.');
            }
            foreach ($result['entries'] as $entry) {
                if (! is_string($entry['name'] ?? null) || $entry['name'] === '' || strlen($entry['name']) > 255
                    || in_array($entry['name'], ['.', '..'], true) || str_contains($entry['name'], '/') || str_contains($entry['name'], "\0")
                    || ! in_array($entry['kind'] ?? null, ['regular', 'directory', 'symlink', 'unknown', 'other'], true)
                    || ! $this->validCookie($entry['cookie'] ?? null)) {
                    throw new RuntimeException('Invalid native directory entry.');
                }
            }

            return $result;
        } catch (\Throwable $e) {
            return ['state' => 'held', 'reason' => 'directory_page_unavailable', 'entries' => [], 'complete' => false];
        }
    }

    public function physicalScope(string $scope, string $directory): array
    {
        foreach ([$scope, $directory] as $path) {
            if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, "\0")
                || str_contains($path, '\\') || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)
                || ($path !== '/' && str_ends_with($path, '/'))) {
                throw new RuntimeException('Invalid logical discovery scope.');
            }
        }
        if ($directory !== $scope && ! str_starts_with($directory, rtrim($scope, '/').'/')) {
            throw new RuntimeException('Directory is outside the discovery scope.');
        }
        clearstatcache(true);
        $data = realpath((string) config('services.nextcloud.data_path'));
        $root = $data === false ? false : realpath($data.($scope === '/' ? '' : $scope));
        if ($data === false || $root === false || ! is_dir($root)
            || ($root !== $data && ! str_starts_with($root, $data.'/'))) {
            throw new RuntimeException('Contained local discovery scope unavailable.');
        }
        $suffix = $directory === $scope ? '' : substr($directory, strlen(rtrim($scope, '/')));
        $target = $root.$suffix;
        if (realpath($target) !== $target || ! is_dir($target)) {
            throw new RuntimeException('Current contained directory unavailable.');
        }
        $rootProof = $this->proof($root);
        $directoryProof = $this->proof($target);

        return ['root' => $root, 'directory' => $target, 'root_proof' => $rootProof, 'directory_proof' => $directoryProof,
            'connector_fingerprint' => hash('sha256', json_encode([$data, $root, $rootProof['dev'], $rootProof['ino']], JSON_THROW_ON_ERROR))];
    }

    public function policy(): array
    {
        $policy = $this->systemConfig->get('files.registry_discovery_policy', config('file_registry.discovery.policy'));
        if (is_string($policy)) {
            $policy = json_decode($policy, true);
        }
        if (! is_array($policy)) {
            throw new RuntimeException('Discovery policy unavailable.');
        }
        $bounds = config('file_registry.discovery.bounds');
        if (! is_array($bounds) || $bounds === []) {
            throw new RuntimeException('Discovery bounds unavailable.');
        }
        foreach ($bounds as $key => $ceiling) {
            $value = $policy[$key] ?? null;
            if (! is_int($value) || ! is_int($ceiling) || $value < ($key === 'max_depth' ? 0 : 1) || $ceiling < ($key === 'max_depth' ? 0 : 1)) {
                throw new RuntimeException('Discovery policy is outside its runtime bounds.');
            }
            $policy[$key] = min($value, $ceiling);
        }

        return $policy;
    }

    private function python(): string
    {
        $binary = config('file_registry.discovery.python_binary');
        if (! is_string($binary) || ! is_executable($binary)) {
            throw new RuntimeException('Native directory runtime unavailable.');
        }

        return $binary;
    }

    protected function run(array $command, string $input, float $timeout, int $maxOutput): array
    {
        if ($timeout <= 0 || $maxOutput < 1 || ! is_file($command[1])) {
            throw new RuntimeException('Native helper admission unavailable.');
        }
        $process = new Process($command, base_path(), ['PYTHONDONTWRITEBYTECODE' => '1'], $input, $timeout);
        $bytes = 0;
        try {
            $process->run(function ($type, $buffer) use (&$bytes, $maxOutput): void {
                $bytes += strlen($buffer);
                if ($bytes > $maxOutput) {
                    throw new RuntimeException('Native helper output exceeded its bound.');
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Native helper did not complete successfully.');
            }
            $decoded = json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Native helper output is not structured.');
            }

            return $decoded;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    private function fixtureParent(?string $provided): string
    {
        clearstatcache(true);
        $source = realpath((string) config('services.nextcloud.data_path'));
        if ($source === false) {
            throw new RuntimeException('Qualification source unavailable.');
        }
        $sourceProof = $this->proof($source);
        $parent = $provided ?? storage_path(config('file_registry.discovery.qualification_directory'));
        if ($provided !== null && (! is_dir($parent) || realpath($parent) !== $parent)) {
            throw new RuntimeException('A provided qualification parent must already exist without symlink aliases.');
        }
        if ($provided === null && (! is_dir($parent) || $this->proof($parent)['dev'] !== $sourceProof['dev'])) {
            // Reject an existing symlink ancestor before choosing or creating any fixture leaf.
            $ancestor = $parent;
            for ($level = 0; ! file_exists($ancestor) && $level < $this->setting('max_fixture_ancestors'); $level++) {
                $ancestor = dirname($ancestor);
            }
            if (! is_dir($ancestor) || realpath($ancestor) !== $ancestor || $ancestor === $source || str_starts_with($ancestor, $source.'/')) {
                throw new RuntimeException('Qualification ancestor is unsafe or unavailable.');
            }
            // Prefer a bounded, writable ancestor outside the synced source on its exact mount.
            $candidate = dirname($source);
            $parent = null;
            for ($level = 0; $level < $this->setting('max_fixture_ancestors') && $candidate !== '/'; $level++, $candidate = dirname($candidate)) {
                if (is_dir($candidate) && realpath($candidate) === $candidate && is_writable($candidate) && $this->proof($candidate)['dev'] === $sourceProof['dev']) {
                    $parent = $candidate.'/.'.basename(config('file_registry.discovery.qualification_directory'));
                    break;
                }
            }
        }
        if (! is_string($parent) || str_contains($parent, '//') || $parent === $source || str_starts_with($parent, $source.'/')) {
            throw new RuntimeException('Qualification fixtures cannot be created in synced originals.');
        }
        if (! is_dir($parent) && (! is_dir(dirname($parent)) || realpath(dirname($parent)) !== dirname($parent)
            || ! mkdir($parent, 0700))) {
            throw new RuntimeException('Private qualification parent unavailable.');
        }
        clearstatcache(true);
        $real = realpath($parent);
        if ($real === false || $real !== $parent || $real === $source || str_starts_with($real, $source.'/')
            || ! is_writable($real) || (fileperms($real) & 0077) !== 0 || $this->proof($real)['dev'] !== $sourceProof['dev']
            || $this->proof($source) !== $sourceProof) {
            throw new RuntimeException('Qualification parent must be private and outside synced originals.');
        }

        return $real;
    }

    private function setting(string $key): int
    {
        $value = config('file_registry.discovery.'.$key);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('Missing discovery runtime setting: '.$key);
        }

        return $value;
    }

    private function proof(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || ! is_dir($path)) {
            throw new RuntimeException('Directory proof unavailable.');
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'mtime', 'ctime']));
    }

    private function validCookie(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    private function validReceipt(array $receipt): bool
    {
        $checks = config('file_registry.discovery.qualification_required_checks');

        return ($receipt['state'] ?? null) === 'qualified' && ($receipt['filesystem'] ?? null) === 'ext4'
            && is_string($receipt['runtime_fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $receipt['runtime_fingerprint']) === 1
            && is_string($receipt['helper_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $receipt['helper_sha256']) === 1
            && is_int($receipt['fixture_entries'] ?? null) && $receipt['fixture_entries'] >= $this->setting('qualification_min_fixture_entries')
            && is_int($receipt['independent_process_pages'] ?? null) && $receipt['independent_process_pages'] >= $this->setting('qualification_min_process_pages')
            && is_array($checks) && $checks !== [] && is_array($receipt['checks'] ?? null)
            && array_is_list($receipt['checks']) && count(array_filter($receipt['checks'], 'is_string')) === count($receipt['checks'])
            && array_diff($checks, $receipt['checks']) === [];
    }

    private function matchesReceipt(mixed $proof, array $receipt): bool
    {
        return is_array($proof) && ($proof['runtime_fingerprint'] ?? null) === $receipt['runtime_fingerprint']
            && ($proof['helper_sha256'] ?? null) === $receipt['helper_sha256'] && ($proof['filesystem'] ?? null) === $receipt['filesystem'];
    }
}
