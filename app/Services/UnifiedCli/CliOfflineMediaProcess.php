<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Read-only captured input and installed media tools; no network or app files. */
final class CliOfflineMediaProcess
{
    public function run(string $input, array $command, int $timeout, ?int $memoryMb = null, ?Closure $keeper = null): array
    {
        if (! in_array($command[0] ?? null, ['pdftotext', 'tesseract', 'pdfinfo', 'pdftoppm'], true)) {
            throw new RuntimeException('Unsupported explicit offline media command.');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return (new CliWindowsMediaProcess)->run($input, $command, $timeout, $memoryMb, $keeper);
        }
        $executable = '/usr/bin/'.$command[0];
        $bubblewrap = CliLinuxSandbox::executable();
        if (! is_executable($executable)
            || ($memoryMb !== null && (! is_executable('/usr/bin/prlimit') || $memoryMb < 1 || $memoryMb > 65536))) {
            throw new RuntimeException('The selected local media tool or its process isolation is unavailable on this host.');
        }
        $args = [$bubblewrap, '--die-with-parent', '--new-session', '--unshare-net', '--unshare-pid',
            '--unshare-ipc', '--unshare-uts', '--ro-bind', '/usr', '/usr'];
        foreach (['/lib', '/lib64', '/etc/ld.so.cache', '/etc/fonts', '/var/cache/fontconfig'] as $path) {
            if (file_exists($path)) {
                array_push($args, '--ro-bind', $path, $path);
            }
        }
        array_push($args, '--dev', '/dev', '--proc', '/proc', '--tmpfs', '/tmp',
            '--dir', '/input', '--ro-bind', $input, '/input/source', '--chdir', '/tmp', '--');
        if ($memoryMb !== null) {
            array_push($args, '/usr/bin/prlimit', '--as='.($memoryMb * 1048576), '--');
        }
        array_push($args, $executable, ...array_slice($command, 1));
        $environment = array_fill_keys(array_filter(array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv()))), is_string(...)), false);
        $environment['PATH'] = '/usr/bin:/bin';
        $environment['LANG'] = 'C.UTF-8';
        $environment['OMP_THREAD_LIMIT'] = '1';
        $environment['XDG_CACHE_HOME'] = '/tmp/font-cache';
        $process = new Process($args, '/', $environment, null, $timeout);

        return (new CliMaintainedProcess)->run($process, $keeper);
    }
}
