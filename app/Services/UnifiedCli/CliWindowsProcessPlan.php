<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Host-local executable bindings; launch credentials travel through stdin only. */
final class CliWindowsProcessPlan
{
    public function __construct(
        private readonly string $helper,
        private readonly string $stateDirectory,
        private readonly array $readRoots,
    ) {}

    public static function fromEnvironment(string $plosRoot): self
    {
        return new self((string) getenv('PLOS_CLI_WINDOWS_SANDBOX'),
            (string) getenv('PLOS_CLI_WINDOWS_SANDBOX_STATE'), [$plosRoot]);
    }

    public function build(array $bound, string $network, string $workspace, int $timeoutMs, ?int $memoryMb = null): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! in_array($network, ['offline', 'online'], true)
            || $timeoutMs < 50 || $timeoutMs > 3600000 || ($memoryMb !== null && ($memoryMb < 1 || $memoryMb > 65536))) {
            throw new RuntimeException('Windows execution requires an admitted network mode and deadline.');
        }
        $helper = $this->executable($this->helper);
        foreach (['codex-command-runner.exe', 'codex-windows-sandbox-setup.exe'] as $name) {
            $this->executable(dirname($helper).DIRECTORY_SEPARATOR.$name);
        }
        $executable = $this->executable($bound['command'] ?? '');
        $workspace = $this->directory($workspace);
        $state = $this->directory($this->stateDirectory);
        $read = array_values(array_unique(array_map($this->directory(...),
            [...$this->readRoots, dirname($executable), $workspace])));
        foreach ($read as $root) {
            if ($this->within($state, $root) || $this->within($root, $state)) {
                throw new RuntimeException('Windows sandbox state must be outside tool-readable roots.');
            }
        }
        if ($this->within($helper, $workspace) || $this->within($executable, $workspace)) {
            throw new RuntimeException('Windows execution binaries must be outside the writable workspace.');
        }
        $arguments = $bound['args'] ?? [];
        $environment = $bound['env'] ?? [];
        if (! is_array($arguments) || ! array_is_list($arguments) || ! is_array($environment)) {
            throw new RuntimeException('Invalid Windows process arguments or environment.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new RuntimeException('Windows process arguments must be literal strings.');
            }
        }
        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)
                || ! is_string($value) || str_contains($value, "\0")) {
                throw new RuntimeException('Invalid Windows process environment binding.');
            }
        }
        $systemRoot = $this->directory((string) getenv('SystemRoot'));
        $base = ['SystemRoot' => $systemRoot, 'PATH' => dirname($executable)];

        return ['command' => $helper, 'args' => ['--plan-stdin'], 'cwd' => $workspace,
            'env' => $base, 'windows_plan' => [
                'schema' => 'plos.cli.windows-process.v1', 'command' => [$executable, ...$arguments],
                'cwd' => $workspace, 'state_directory' => $state, 'read_roots' => $read,
                'write_roots' => [$workspace],
                'env' => array_replace($base, ['TEMP' => $workspace, 'TMP' => $workspace], $environment),
                'network' => $network, 'timeout_ms' => $timeoutMs,
                ...($memoryMb === null ? [] : ['memory_mb' => $memoryMb]),
            ]];
    }

    private function executable(string $path): string
    {
        $resolved = realpath($path);
        if (! preg_match('~^[A-Za-z]:[\\\\/]~', $path) || $resolved === false || ! is_file($resolved)
            || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'exe') {
            throw new RuntimeException('An explicit installed Windows executable is required.');
        }

        return $resolved;
    }

    private function directory(string $path): string
    {
        $resolved = realpath($path);
        if (! preg_match('~^[A-Za-z]:[\\\\/]~', $path) || $resolved === false || ! is_dir($resolved)
            || dirname($resolved) === $resolved) {
            throw new RuntimeException('An explicit existing Windows directory is required.');
        }

        return $resolved;
    }

    private function within(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
