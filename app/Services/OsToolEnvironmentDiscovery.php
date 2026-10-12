<?php

namespace App\Services;

use Closure;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Untrusted observations, never executable admission or binary/package ownership proof. */
class OsToolEnvironmentDiscovery
{
    private const DEFAULT_LIMITS = [
        'directories' => 32, 'entries' => 32768, 'executables' => 8192, 'aliases_per_executable' => 256,
        'packages' => 4096, 'path_bytes' => 16384, 'package_bytes' => 1048576,
        'output_bytes' => 4194304, 'duration_ms' => 4000, 'package_timeout_ms' => 2000,
    ];

    private array $privatePaths = [];

    private array $limits;

    public function __construct(
        private readonly ?array $directories = null,
        private readonly ?Closure $packageRunner = null,
        private readonly ?Closure $environment = null,
        private readonly ?Closure $clock = null,
        array $limits = [],
    ) {
        foreach ($limits as $key => $value) {
            if (! isset(self::DEFAULT_LIMITS[$key]) || ! is_int($value) || $value < 1 || $value > self::DEFAULT_LIMITS[$key]
                || ($key === 'output_bytes' && $value < 2048)) {
                throw new InvalidArgumentException('Discovery limits may only reduce known positive bounds.');
            }
        }
        $this->limits = array_replace(self::DEFAULT_LIMITS, $limits);
    }

    public function discover(): array
    {
        $this->privatePaths = [];
        $start = $this->milliseconds();
        $deadline = $start + $this->limits['duration_ms'];
        $environment = $this->environment === null
            ? ['path' => getenv('PATH') ?: '', 'os' => PHP_OS_FAMILY, 'pathext' => getenv('PATHEXT') ?: '']
            : ($this->environment)();
        $errors = [];
        $truncation = [];
        $path = $environment['path'] ?? '';
        if (! is_string($path) || strlen($path) > $this->limits['path_bytes']) {
            $path = '';
            $truncation[] = 'path_bytes';
        }
        $windows = ($environment['os'] ?? null) === 'Windows';
        $extensions = $windows ? $this->windowsExtensions($environment['pathext'] ?? '', $errors) : [];
        $directories = array_values($this->directories ?? explode($windows ? ';' : PATH_SEPARATOR, $path));
        if (count($directories) > $this->limits['directories']) {
            $directories = array_slice($directories, 0, $this->limits['directories']);
            $truncation[] = 'directories';
        }
        $executables = [];
        $seenDirectories = [];
        $nameOwners = [];
        $entries = 0;
        $skippedNames = 0;
        foreach ($directories as $position => $directory) {
            if ($this->milliseconds() >= $deadline) {
                $truncation[] = 'duration_ms';
                break;
            }
            // Empty and relative PATH components imply the working directory; do not inspect it implicitly.
            $directory = $this->observationDirectory($directory, $windows);
            if ($directory === null) {
                $errors[] = 'unsafe_path_component_skipped';

                continue;
            }
            clearstatcache(true, $directory);
            $resolvedDirectory = @realpath($directory);
            if ($resolvedDirectory === false || ! @is_dir($resolvedDirectory) || ! @is_readable($resolvedDirectory)) {
                $errors[] = 'path_directory_unavailable';

                continue;
            }
            if (isset($seenDirectories[$resolvedDirectory])) {
                continue;
            }
            $seenDirectories[$resolvedDirectory] = true;
            $handle = @opendir($resolvedDirectory);
            if ($handle === false) {
                $errors[] = 'path_directory_unavailable';

                continue;
            }
            try {
                while (($name = readdir($handle)) !== false) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    if ($entries >= $this->limits['entries'] || $this->milliseconds() >= $deadline) {
                        $truncation[] = $entries >= $this->limits['entries'] ? 'entries' : 'duration_ms';
                        break 2;
                    }
                    $entries++;
                    if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,127}$/D', $name)) {
                        $skippedNames++;

                        continue;
                    }
                    $candidate = $resolvedDirectory.'/'.$name;
                    clearstatcache(true, $candidate);
                    $resolved = @realpath($candidate);
                    $extensionObserved = $windows && in_array('.'.strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true);
                    if ($resolved === false || ! @is_file($resolved) || (! $extensionObserved && ! @is_executable($candidate))) {
                        continue;
                    }
                    $stat = @stat($resolved);
                    if ($stat === false) {
                        $errors[] = 'executable_stat_unavailable';

                        continue;
                    }
                    // No content hash or version probe. Windows filename extensions are untrusted discovery hints.
                    // This is an observation key, not an execution pin.
                    $id = hash('sha256', json_encode([$stat['dev'], $stat['ino']], JSON_THROW_ON_ERROR));
                    if (! isset($executables[$id])) {
                        if (count($executables) >= $this->limits['executables']) {
                            $truncation[] = 'executables';
                            break 2;
                        }
                        $executables[$id] = ['id' => $id, 'names' => [], 'path_positions' => [], 'shadowed_names' => [],
                            'sources' => ['path'], 'qualification' => 'unverified', 'execution_authorized' => false];
                        $this->privatePaths[$id] = ['resolved_path' => $resolved, 'paths' => []];
                    }
                    $row = &$executables[$id];
                    if (! in_array($name, $row['names'], true)) {
                        if (count($row['names']) >= $this->limits['aliases_per_executable']) {
                            $truncation[] = 'aliases_per_executable';
                            unset($row);

                            continue;
                        }
                        $row['names'][] = $name;
                        $nameKey = $windows ? strtolower($name) : $name;
                        if (isset($nameOwners[$nameKey]) && $nameOwners[$nameKey] !== $id) {
                            $row['shadowed_names'][] = $name;
                        }
                        $nameOwners[$nameKey] ??= $id;
                    }
                    if (! in_array($position, $row['path_positions'], true)) {
                        $row['path_positions'][] = $position;
                    }
                    unset($row);
                    if (count($this->privatePaths[$id]['paths']) < $this->limits['aliases_per_executable']) {
                        $this->privatePaths[$id]['paths'][] = $candidate;
                    }
                }
            } finally {
                closedir($handle);
            }
        }
        $packages = [];
        $packageStatus = 'unsupported_distribution';
        if (($environment['os'] ?? null) === 'Linux') {
            $remaining = (int) max(0, $deadline - $this->milliseconds());
            if ($remaining === 0) {
                $truncation[] = 'duration_ms';
                $packageStatus = 'not_checked_deadline';
            } else {
                try {
                    $response = $this->packageRunner === null
                        ? $this->queryPackages(min($remaining, $this->limits['package_timeout_ms']))
                        : ($this->packageRunner)(min($remaining, $this->limits['package_timeout_ms']), $this->limits['package_bytes']);
                    $packageStatus = $response['status'] ?? 'failed';
                    if (($response['truncated'] ?? false) === true || strlen($response['stdout'] ?? '') > $this->limits['package_bytes']) {
                        $truncation[] = 'package_bytes';
                        $packageStatus = 'failed_output_limit';
                    } elseif (($response['timed_out'] ?? false) === true) {
                        $truncation[] = 'package_timeout_ms';
                        $packageStatus = 'failed_timeout';
                    } elseif (($response['exit_code'] ?? null) === 0 && ($response['stderr'] ?? '') === '') {
                        $packageStatus = 'observed';
                        $packages = $this->parsePackages($response['stdout'] ?? '', $deadline, $truncation, $errors);
                    } elseif ($packageStatus !== 'unsupported_distribution') {
                        $errors[] = 'package_query_failed';
                    }
                } catch (Throwable) {
                    $packageStatus = 'failed';
                    $errors[] = 'package_query_failed';
                }
            }
        }
        foreach ($executables as &$executable) {
            sort($executable['names']);
            sort($executable['shadowed_names']);
        }
        unset($executable);
        $result = ['schema' => 'plos.os-tools.environment.v1', 'observed_at' => gmdate(DATE_ATOM),
            'complete_machine_inventory' => false, 'metadata_untrusted' => true, 'raw_paths_included' => false,
            'runtime_probes_executed' => false, 'execution_authorized' => false,
            'platform' => $environment['os'] ?? 'unknown', 'supported_sources' => ['path' => $windows ? 'absolute_windows_drive_path_entries_only' : 'absolute_posix_path_entries_only', 'packages' => ($environment['os'] ?? null) === 'Linux' ? 'protected_dpkg_if_present' : 'unsupported_platform'],
            'identity_basis' => 'device_inode_observation_not_execution_pin', 'names_are_tool_identities' => false,
            'executable_detection' => $windows ? 'windows_extension_or_php_flag_not_format_or_permission' : 'posix_executable_bit',
            'windows_extension_hints' => $extensions,
            'storage_locality_verified' => false, 'mounted_or_mapped_network_storage_excluded' => false,
            'binary_package_ownership' => 'not_checked', 'filesystem_deadline' => 'cooperative_between_entries',
            'executable_observations' => array_values($executables), 'installed_packages' => $packages,
            'sources' => ['path' => ['directories_observed' => count($seenDirectories), 'entries_examined' => $entries,
                'suspicious_names_skipped' => $skippedNames], 'dpkg' => ['status' => $packageStatus]],
            'limits' => $this->limits, 'truncated' => $truncation !== [], 'truncation_reasons' => array_values(array_unique($truncation)),
            'errors' => array_values(array_unique($errors)), 'elapsed_ms' => max(0, (int) ($this->milliseconds() - $start))];
        while (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > $this->limits['output_bytes']) {
            $result['truncated'] = true;
            if (! in_array('output_bytes', $result['truncation_reasons'], true)) {
                $result['truncation_reasons'][] = 'output_bytes';
            }
            if ($result['installed_packages'] !== []) {
                $result['installed_packages'] = array_slice($result['installed_packages'], 0, intdiv(count($result['installed_packages']), 2));
            } elseif ($result['executable_observations'] !== []) {
                $result['executable_observations'] = array_slice($result['executable_observations'], 0, intdiv(count($result['executable_observations']), 2));
            } else {
                break;
            }
        }
        $returnedIds = array_column($result['executable_observations'], 'id');
        $this->privatePaths = array_intersect_key($this->privatePaths, array_flip($returnedIds));

        return $result;
    }

    /** Trusted PHP callers only; do not expose/register this method as a model tool. */
    public function privateExecutablePaths(): array
    {
        return $this->privatePaths;
    }

    /** PATHEXT suggests names to inspect; it cannot authorize execution or prove a valid binary. */
    private function windowsExtensions(mixed $value, array &$errors): array
    {
        $extensions = ['.exe', '.com', '.bat', '.cmd'];
        if (! is_string($value) || strlen($value) > 1024 || count(explode(';', $value)) > 32) {
            $errors[] = 'invalid_pathext_metadata';

            return $extensions;
        }
        foreach (explode(';', $value) as $extension) {
            if ($extension === '') {
                continue;
            }
            if (! preg_match('/^\.[A-Za-z0-9]{1,12}$/D', $extension)) {
                $errors[] = 'invalid_pathext_metadata';

                continue;
            }
            $extensions[] = strtolower($extension);
        }

        return array_values(array_unique($extensions));
    }

    /** Observe only explicit drive roots. Never expand relative drives, UNC, device paths or variables. */
    private function observationDirectory(mixed $directory, bool $windows): ?string
    {
        if (! is_string($directory) || preg_match('/[\x00-\x1f\x7f]/', $directory)) {
            return null;
        }
        if (! $windows) {
            return str_starts_with($directory, '/') ? $directory : null;
        }
        if (strlen($directory) >= 2 && $directory[0] === '"' && str_ends_with($directory, '"')) {
            $directory = substr($directory, 1, -1);
        }
        $directory = str_replace('\\', '/', $directory);
        if (! preg_match('~^[A-Za-z]:/[^:*?"<>|%]*$~D', $directory)) {
            return null;
        }

        return $directory;
    }

    private function parsePackages(string $output, float $deadline, array &$truncation, array &$errors): array
    {
        $packages = [];
        foreach (explode("\n", $output) as $line) {
            if ($line === '') {
                continue;
            }
            if ($this->milliseconds() >= $deadline || count($packages) >= $this->limits['packages']) {
                $truncation[] = count($packages) >= $this->limits['packages'] ? 'packages' : 'duration_ms';
                break;
            }
            $fields = explode("\t", $line);
            if (count($fields) !== 5) {
                $errors[] = 'invalid_package_metadata';

                continue;
            }
            [$status, $name, $version, $section, $description] = $fields;
            if ($status !== 'installed') {
                continue;
            }
            if (! preg_match('/^[a-z0-9][a-z0-9+.\-]{0,127}(?::[a-z0-9][a-z0-9\-]{0,31})?$/D', $name)
                || ! preg_match('/^[A-Za-z0-9.+:~_\-]{1,128}$/D', $version)
                || ! preg_match('/^[a-z0-9][a-z0-9+\/._\-]{0,79}$/D', $section)
                || ! mb_check_encoding($description, 'UTF-8') || preg_match('/[\p{C}]/u', $description)) {
                $errors[] = 'invalid_package_metadata';

                continue;
            }
            $description = strip_tags($description);
            $description = preg_replace('~(?<![A-Za-z0-9])(?:/[^\s,;]+|[A-Za-z]:\\\\[^\s,;]+)~u', '[path]', $description);
            $packages[$name] = ['name' => $name, 'version' => $version, 'section' => $section,
                'short_description' => mb_substr(trim($description), 0, 160), 'sources' => ['dpkg_installed_metadata']];
        }

        return array_values($packages);
    }

    private function queryPackages(int $timeoutMs): array
    {
        if (! is_executable('/usr/bin/dpkg-query')) {
            return ['status' => 'unsupported_distribution'];
        }
        if (! OsToolDiscoveryService::protectedPath('/usr/bin/dpkg-query')) {
            return ['status' => 'unavailable_untrusted_dpkg'];
        }
        $environment = array_fill_keys(array_keys(getenv()), false);
        $environment['LC_ALL'] = 'C';
        $process = new Process(['/usr/bin/dpkg-query', '--show', '--showformat=${db:Status-Status}\t${binary:Package}\t${Version}\t${Section}\t${binary:Summary}\n'], '/', $environment);
        $process->setTimeout($timeoutMs / 1000);
        $stdout = '';
        $stderr = '';
        $overflow = false;
        try {
            // A callback receives every chunk before buffer cleanup. Separate incremental
            // stdout/stderr reads can otherwise clear bytes read by the other call.
            $process->start(function (string $type, string $chunk) use (&$stdout, &$stderr, &$overflow): void {
                $limit = $type === Process::OUT ? $this->limits['package_bytes'] : 1024;
                $buffer = &$stdout;
                if ($type !== Process::OUT) {
                    $buffer = &$stderr;
                }
                $remaining = max(0, $limit - strlen($buffer));
                if (strlen($chunk) > $remaining) {
                    $overflow = true;
                }
                $buffer .= substr($chunk, 0, $remaining);
                unset($buffer);
            });
            do {
                $process->checkTimeout();
                $running = $process->isRunning();
                $process->clearOutput();
                $process->clearErrorOutput();
                if ($overflow) {
                    return ['status' => 'failed_output_limit', 'truncated' => true];
                }
                if (! $running) {
                    break;
                }
                usleep(1000);
            } while (true);

            return ['status' => 'observed', 'exit_code' => $process->getExitCode(), 'stdout' => $stdout, 'stderr' => $stderr];
        } catch (ProcessTimedOutException) {
            return ['status' => 'failed_timeout', 'timed_out' => true];
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    private function milliseconds(): float
    {
        return $this->clock === null ? hrtime(true) / 1000000 : ($this->clock)();
    }
}
