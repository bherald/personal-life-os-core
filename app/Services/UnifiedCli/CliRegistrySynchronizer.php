<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CliRegistrySynchronizer
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $stateDirectory,
        private readonly string $source,
        private readonly ?string $pythonExecutable = null,
        private readonly ?Closure $developmentDatabaseSync = null,
    ) {}

    public function sync(string $network = 'lan', array $sessionOverrides = [], string $scope = 'registry'): string
    {
        [$snapshot, $expected] = $this->fetch($network, $scope);
        // Reject incompatible saved/session choices before any development import.
        (new CliRegistryOverlay)->apply($snapshot, array_replace($this->store->defaults(), $sessionOverrides));
        if ($scope === 'development') {
            ($this->developmentDatabaseSync)($expected);
        }

        return $this->store->activate($snapshot, $sessionOverrides);
    }

    public function preview(string $network = 'lan', array $sessionOverrides = []): array
    {
        [$snapshot] = $this->fetch($network, 'registry');
        (new CliRegistryOverlay)->apply($snapshot, array_replace($this->store->defaults(), $sessionOverrides));

        return $this->store->registryPreview($snapshot);
    }

    private function fetch(string $network, string $scope): array
    {
        if (! in_array($scope, ['registry', 'development'], true)) {
            throw new RuntimeException('Sync scope must be registry or development; previous generation retained.');
        }
        if ($scope === 'development' && $this->developmentDatabaseSync === null) {
            throw new RuntimeException('Development application binding is unavailable; previous generation retained.');
        }
        if (! in_array($network, ['lan', 'online'], true)) {
            throw new RuntimeException('Network mode is offline; previous generation retained.');
        }
        $directory = realpath($this->stateDirectory);
        if ($directory === false) {
            throw new RuntimeException('Private CLI state directory is unavailable.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'registry'.DIRECTORY_SEPARATOR.'source-'.bin2hex(random_bytes(12)).'.json';
        $configuredPython = getenv('PLOS_CLI_SYNC_PYTHON');
        $python = $this->pythonExecutable ?? (is_string($configuredPython) ? $configuredPython : (PHP_OS_FAMILY === 'Windows' ? '' : 'python3'));
        if ($python === '' || str_contains($python, "\0")) {
            throw new RuntimeException('Registry sync Python interpreter is unavailable; previous generation retained.');
        }
        $arguments = [$python, dirname(__DIR__, 3).'/scripts/plos-cli/sync-registry.py',
            '--source', $this->source, '--network', $network, '--output', $path];
        if ($this->source === 'local') {
            $arguments[] = '--php';
            $arguments[] = PHP_BINARY;
        }
        if ($scope === 'development') {
            $arguments[] = '--include-schema';
        }
        $process = new Process($arguments);
        $process->setTimeout(150);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(self::publicFailure($process->getErrorOutput()));
        }
        $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $reported = is_string($receipt['output'] ?? null) ? realpath($receipt['output']) : false;
        $expected = realpath($path);
        if (($receipt['schema'] ?? '') !== 'plos.cli.sync-receipt.v1' || $reported === false || $expected === false
            || (PHP_OS_FAMILY === 'Windows' ? strcasecmp($reported, $expected) !== 0 : $reported !== $expected)
            || ! is_file($path) || is_link($path) || ! is_string($receipt['sha256'] ?? null)) {
            throw new RuntimeException('Registry export receipt failed verification; previous generation retained.');
        }
        $original = CliLocalBindings::readPrivateFile($expected);
        if (! hash_equals($receipt['sha256'], hash('sha256', $original))) {
            throw new RuntimeException('Registry export digest failed verification; previous generation retained.');
        }

        $snapshot = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        (new RegistrySnapshotValidator)->validate($snapshot);

        return [$snapshot, $expected];
    }

    /** Return only fixed operator diagnostics; never relay SSH or process details. */
    public static function publicFailure(string $message): string
    {
        $message = trim($message);
        $allowed = [
            'Development schema/config sync failed; registry generation not activated.',
            'Development application binding is unavailable; previous generation retained.',
            'Production database cannot be a development sync target; previous generation retained.',
            'Sync scope must be registry or development; previous generation retained.',
            'Registry SSH host verification failed; configure a trusted host key; previous generation retained.',
            'Registry SSH login unavailable; configure a host-local identity; previous generation retained.',
            'Selected SSH configuration is unavailable; previous generation retained.',
        ];

        return in_array($message, $allowed, true) ? $message
            : 'Registry source unavailable or export rejected; previous generation retained.';
    }
}
