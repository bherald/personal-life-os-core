<?php

namespace App\Services\UnifiedCli;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Windows ACL verification emits a digest only, never file contents. */
final class CliWindowsPrivateFile
{
    /** ACL-only fixed SQLite family inspection permits concurrent database readers/writers. */
    public static function verifySqlite(string $path): void
    {
        $system = getenv('SystemRoot');
        $powershell = is_string($system) ? $system.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : '';
        $checker = dirname(__DIR__, 3).'/scripts/plos-cli/check-private-sqlite.ps1';
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($powershell) || ! is_file($checker)) {
            throw new RuntimeException('Windows mutable SQLite custody verification is unavailable.');
        }
        $process = new Process([$powershell, '-NoProfile', '-NonInteractive', '-File', $checker,
            '-PathBase64', base64_encode($path)], timeout: 20);
        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (($result['schema'] ?? null) !== 'plos.cli.private-sqlite.v1' || ($result['private'] ?? false) !== true
                || ($result['path_sha256'] ?? null) !== hash('sha256', strtolower(str_replace('/', '\\', $path)))) {
                throw new RuntimeException('SQLite custody evidence differs from the selected path.');
            }
        } catch (\Throwable) {
            throw new RuntimeException('Worker SQLite journal must have verified private Windows custody.');
        }
    }

    public static function verify(string $path, string $bytes): void
    {
        $system = getenv('SystemRoot');
        $powershell = is_string($system) ? $system.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : '';
        $checker = dirname(__DIR__, 3).'/scripts/plos-cli/check-private-file.ps1';
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($powershell) || ! is_file($checker)) {
            throw new RuntimeException('Windows private-file verification is unavailable.');
        }
        try {
            $process = new Process([$powershell, '-NoProfile', '-NonInteractive', '-File', $checker,
                '-PathBase64', base64_encode($path)], timeout: 20);
            $process->run();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (! $process->isSuccessful() || ($result['schema'] ?? null) !== 'plos.cli.private-file.v1'
                || ($result['bytes'] ?? null) !== strlen($bytes)
                || ! is_string($result['sha256'] ?? null) || ! hash_equals(hash('sha256', $bytes), $result['sha256'])) {
                throw new RuntimeException('Windows file identity or permissions were not verified.');
            }
        } catch (\Throwable) {
            throw new RuntimeException('Local binding file must have verified private Windows permissions.');
        }
    }
}
