<?php

namespace App\Services;

class McpExternalRuntimeAdmissionService
{
    private readonly string $basePath;

    private const INDIRECT_LAUNCHERS = [
        'bash',
        'bun',
        'bunx',
        'cargo',
        'deno',
        'docker',
        'docker-compose',
        'dotnet',
        'fish',
        'go',
        'npm',
        'npx',
        'pipx',
        'pnpm',
        'podman',
        'poetry',
        'powershell',
        'pwsh',
        'sh',
        'uvx',
        'yarn',
        'zsh',
    ];

    private const INTERPRETERS = ['java', 'node', 'php', 'python', 'python3', 'ruby'];

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path();
    }

    /**
     * @param  array<string,mixed>  $serverConfig
     * @return array{admitted: bool, reason_code: string, pin_type: string}
     */
    public function inspect(array $serverConfig): array
    {
        $transport = strtolower(trim((string) ($serverConfig['transport'] ?? '')));
        if ($transport !== '' && ! in_array($transport, ['external_process', 'stdio'], true)) {
            return $this->denied('unsupported_transport');
        }

        foreach (['url', 'headers', 'request_init', 'requestInit'] as $remoteOption) {
            if (array_key_exists($remoteOption, $serverConfig)) {
                return $this->denied('unsupported_transport_option');
            }
        }

        $command = trim((string) ($serverConfig['command'] ?? ''));
        if ($command === '') {
            return $this->denied('command_missing');
        }

        $executable = preg_replace('/\.(?:exe|cmd|bat)$/i', '', strtolower(basename(str_replace('\\', '/', $command))));
        if (in_array($executable, self::INDIRECT_LAUNCHERS, true)) {
            return $this->denied('indirect_launcher');
        }

        $args = array_values(array_filter(
            (array) ($serverConfig['args'] ?? []),
            static fn (mixed $arg): bool => is_string($arg)
        ));
        if (in_array($executable, ['python', 'python3'], true) && in_array('-m', $args, true)) {
            return $this->denied('module_launcher');
        }

        $resolvedCommand = $this->resolveRuntimeFilePath($command, $serverConfig, true);
        if ($resolvedCommand === null) {
            return $this->denied('command_unresolved');
        }

        if (in_array($executable, self::INTERPRETERS, true)
            && ! $this->hasResolvedFileArgument($args, $serverConfig)) {
            return $this->denied('entrypoint_unresolved');
        }

        $pin = $serverConfig['runtime_pin'] ?? null;
        if ($pin === null) {
            return $this->admitted('content_identity');
        }
        if (! is_array($pin) || ($pin['type'] ?? null) !== 'npm_lock') {
            return $this->denied('pin_invalid');
        }

        if ($executable === 'node') {
            $entrypoint = isset($args[0]) && ! str_starts_with($args[0], '-')
                ? $this->resolveRuntimeFilePath($args[0], $serverConfig, false) : null;
            if ($entrypoint === null) {
                return $this->denied('entrypoint_unresolved');
            }
            $admission = $this->inspectNpmLockPin($pin, $entrypoint);
            if (! $admission['admitted']) {
                return $admission;
            }
            $installed = $this->readJsonFile($this->projectPath('node_modules/'.$pin['package'].'/package.json'));
            foreach ((array) ($installed['bin'] ?? []) as $relative) {
                if (is_string($relative) && realpath($this->projectPath('node_modules/'.$pin['package'].'/'.$relative)) === $entrypoint) {
                    return $admission;
                }
            }

            return $this->denied('entrypoint_not_declared');
        }

        return $this->inspectNpmLockPin($pin, $resolvedCommand);
    }

    /**
     * @param  array<string,mixed>  $pin
     * @return array{admitted: bool, reason_code: string, pin_type: string}
     */
    private function inspectNpmLockPin(array $pin, string $resolvedCommand): array
    {
        $package = trim((string) ($pin['package'] ?? ''));
        $version = trim((string) ($pin['version'] ?? ''));
        if (! preg_match('#^(?:@[a-z0-9._-]+/)?[a-z0-9._-]+$#i', $package)
            || ! preg_match('/^[0-9][0-9A-Za-z.+-]*$/', $version)) {
            return $this->denied('pin_invalid');
        }

        $manifest = $this->readJsonFile($this->projectPath('package.json'));
        $declared = $manifest['dependencies'][$package]
            ?? $manifest['devDependencies'][$package]
            ?? null;
        if (! is_string($declared) || ! hash_equals($version, $declared)) {
            return $this->denied('manifest_pin_mismatch');
        }

        $lock = $this->readJsonFile($this->projectPath('package-lock.json'));
        $lockPackage = $lock['packages']['node_modules/'.$package] ?? null;
        if (! is_array($lockPackage)
            || ! is_string($lockPackage['version'] ?? null)
            || ! hash_equals($version, (string) $lockPackage['version'])
            || ! is_string($lockPackage['integrity'] ?? null)
            || ! preg_match('/^sha(?:256|384|512)-[A-Za-z0-9+\/=]+$/', (string) $lockPackage['integrity'])) {
            return $this->denied('lock_pin_mismatch');
        }

        $packageDirectory = realpath($this->projectPath('node_modules/'.$package));
        $installed = $this->readJsonFile($this->projectPath('node_modules/'.$package.'/package.json'));
        if ($packageDirectory === false
            || ! is_string($installed['version'] ?? null)
            || ! hash_equals($version, (string) $installed['version'])) {
            return $this->denied('installed_pin_mismatch');
        }

        $packagePrefix = rtrim($packageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($resolvedCommand, $packagePrefix)) {
            return $this->denied('binary_package_mismatch');
        }

        return $this->admitted('npm_lock');
    }

    /** @param array<int,string> $args @param array<string,mixed> $serverConfig */
    private function hasResolvedFileArgument(array $args, array $serverConfig): bool
    {
        foreach ($args as $argument) {
            if ($argument !== ''
                && ! str_starts_with($argument, '-')
                && $this->resolveRuntimeFilePath($argument, $serverConfig, false) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $serverConfig */
    private function resolveRuntimeFilePath(string $candidate, array $serverConfig, bool $searchPath): ?string
    {
        $home = $this->runtimeEnvValue('HOME');
        if (str_starts_with($candidate, '~/') && $home !== '') {
            $candidate = $home.substr($candidate, 1);
        }

        $paths = [];
        if (str_starts_with($candidate, '/')
            || (PHP_OS_FAMILY === 'Windows' && preg_match('~^[A-Za-z]:[/\\\\]~', $candidate))) {
            $paths[] = $candidate;
        } else {
            $paths[] = $this->projectPath($candidate);
            if ($searchPath && ! str_contains($candidate, '/')) {
                $configuredPath = (string) (($serverConfig['env']['PATH'] ?? null)
                    ?: $this->runtimeEnvValue('PATH'));
                foreach (explode(PATH_SEPARATOR, $configuredPath) as $directory) {
                    if ($directory !== '') {
                        $paths[] = rtrim($directory, '/').'/'.$candidate;
                    }
                }

                if ($home !== '') {
                    $paths[] = $home.'/.npm-global/bin/'.$candidate;
                }
            }
        }

        foreach (array_unique($paths) as $path) {
            $usable = $searchPath ? is_executable($path) : is_readable($path);
            if (is_file($path) && $usable) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function runtimeEnvValue(string $key): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        return is_string($_ENV[$key] ?? null) ? (string) $_ENV[$key] : '';
    }

    private function projectPath(string $path): string
    {
        return rtrim($this->basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    /** @return array{admitted: bool, reason_code: string, pin_type: string} */
    private function admitted(string $pinType): array
    {
        return ['admitted' => true, 'reason_code' => 'admitted', 'pin_type' => $pinType];
    }

    /** @return array{admitted: bool, reason_code: string, pin_type: string} */
    private function denied(string $reason): array
    {
        return ['admitted' => false, 'reason_code' => $reason, 'pin_type' => 'none'];
    }
}
