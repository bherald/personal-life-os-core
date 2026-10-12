<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Offline installed media tools with a private read-only input copy. */
final class CliWindowsMediaProcess
{
    private static array $verifiedWork = [];

    public function run(string $input, array $command, int $timeout, ?int $memoryMb, ?Closure $keeper = null): array
    {
        $rootPath = (string) getenv('PLOS_CLI_WINDOWS_MEDIA_ROOT');
        $workPath = (string) getenv('PLOS_CLI_WINDOWS_MEDIA_WORK');
        if (! preg_match('~^[A-Za-z]:[\\\\/]~', $rootPath) || ! preg_match('~^[A-Za-z]:[\\\\/]~', $workPath)) {
            throw new RuntimeException('Windows media requires explicit local tool and private working directories.');
        }
        $root = realpath($rootPath);
        $work = realpath($workPath);
        $input = realpath($input);
        if (PHP_OS_FAMILY !== 'Windows' || $root === false || $work === false || $input === false
            || ! is_dir($root) || ! is_dir($work) || ! is_file($input) || $timeout < 1 || $timeout > 3600) {
            throw new RuntimeException('Windows media requires installed tools and existing private working storage.');
        }
        $this->verifyPrivateWork($work);
        $manifest = json_decode(file_get_contents($root.'/media-tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $binding = $manifest['commands'][$command[0] ?? ''] ?? null;
        if (($manifest['schema'] ?? null) !== 'plos.cli.windows-media.v1' || ! is_array($binding)) {
            throw new RuntimeException('Selected Windows media tool is not installed.');
        }
        $relative = $binding['executable'] ?? '';
        if (! is_string($relative) || ! preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+\.exe$~D', $relative)
            || in_array('..', explode('/', $relative), true) || ! is_file($root.'/'.$relative)
            || hash_file('sha256', $root.'/'.$relative) !== ($binding['sha256'] ?? null)) {
            throw new RuntimeException('Windows media executable identity is unavailable or changed.');
        }
        $directory = $work.'/operation-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private media working directory.');
        }
        $result = null;
        try {
            foreach (['input', 'output'] as $name) {
                if (! mkdir($directory.'/'.$name, 0700)) {
                    throw new RuntimeException('Cannot create media operation directories.');
                }
            }
            $source = $directory.'/input/source';
            $before = hash_file('sha256', $input);
            if (! copy($input, $source) || hash_file('sha256', $source) !== $before || hash_file('sha256', $input) !== $before) {
                throw new RuntimeException('Captured media changed while preparing the read-only input.');
            }
            $scratch = $directory.'/output';
            $bound = ['command' => $root.'/'.$relative,
                'args' => array_map(static fn ($arg) => $arg === '/input/source' ? $source : $arg, array_slice($command, 1)),
                'env' => ['LANG' => 'C.UTF-8', 'OMP_THREAD_LIMIT' => '1', 'XDG_CACHE_HOME' => $scratch.'/font-cache']];
            $backend = $binding['backend'] ?? $command[0];
            if ($backend === 'mupdf_draw') {
                if (count($command) !== 10 || $command[0] !== 'pdftoppm'
                    || $command[1] !== '-f' || $command[3] !== '-l' || $command[2] !== $command[4]
                    || $command[5] !== '-singlefile' || $command[6] !== '-r' || $command[8] !== '-png'
                    || $command[9] !== '/input/source' || ! ctype_digit($command[2]) || (int) $command[2] < 1
                    || ! ctype_digit($command[7]) || (int) $command[7] < 72 || (int) $command[7] > 1200) {
                    throw new RuntimeException('MuPDF requires an explicit complete single-page PNG rendering recipe.');
                }
                // The manifest selects this backend before execution. No retry,
                // alternate-engine fallback, cropping or source modification.
                $bound['args'] = ['draw', '-q', '-F', 'png', '-r', $command[7], '-o', '-', $source, $command[2]];
            } elseif ($backend !== $command[0]) {
                throw new RuntimeException('Unsupported explicit Windows media backend.');
            }
            if ($command[0] === 'tesseract') {
                $data = $manifest['tessdata'] ?? null;
                if (! is_string($data) || ! preg_match('~^[A-Za-z0-9_.-]+/tessdata$~D', $data)
                    || str_contains($data, '..') || ! is_dir($root.'/'.$data)) {
                    throw new RuntimeException('Windows OCR data is unavailable.');
                }
                $bound['env']['TESSDATA_PREFIX'] = $root.'/'.$data;
            }
            $launch = (new CliWindowsProcessPlan((string) getenv('PLOS_CLI_WINDOWS_SANDBOX'),
                (string) getenv('PLOS_CLI_WINDOWS_SANDBOX_STATE'), [$root, $directory.'/input']))->build(
                    $bound, 'offline', $scratch, $timeout * 1000, $memoryMb);
            $json = json_encode($launch['windows_plan'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 16 * 1024 * 1024) {
                throw new RuntimeException('Media launch metadata exceeds the supported bound.');
            }
            $environment = array_fill_keys(array_filter(array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv()))), is_string(...)), false);
            $environment = array_replace($environment, $launch['env']);
            $process = new Process([$launch['command'], ...$launch['args']], $launch['cwd'], $environment,
                pack('V', strlen($json)).$json, $timeout + 5);
            $result = (new CliMaintainedProcess)->run($process, $keeper);
            if ($process->getExitCode() === 124) {
                $result['interrupted'] = true;
                $result['success'] = false;
            }
            $result += ['backend' => $backend,
                'memory_limit_mb' => $memoryMb, 'memory_limit_kind' => $memoryMb === null ? null : 'windows-job-committed'];
        } finally {
            $clean = $this->removeOwnedDirectory($directory);
            if ($result !== null && ! $clean) {
                $result['success'] = false;
                $result['cleanup_complete'] = false;
                $result['private_work_directory'] = $directory;
            }
        }

        return $result;
    }

    private function verifyPrivateWork(string $work): void
    {
        if (isset(self::$verifiedWork[$work])) {
            return;
        }
        $powershell = (string) getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe';
        $checker = dirname(__DIR__, 3).'/scripts/plos-cli/state-directory.ps1';
        if (! is_file($powershell) || ! is_file($checker)) {
            throw new RuntimeException('Windows private media directory verification is unavailable.');
        }
        $process = new Process([$powershell, '-NoProfile', '-NonInteractive', '-File', $checker,
            '-PathBase64', base64_encode($work)], timeout: 20);
        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (($result['schema'] ?? null) !== 'plos.cli.state-directory.v1' || ($result['private'] ?? false) !== true) {
                throw new RuntimeException('Private media directory was not verified.');
            }
        } catch (\Throwable) {
            throw new RuntimeException('Windows media working directory must have private operator permissions.');
        }
        self::$verifiedWork[$work] = true;
    }

    private function removeOwnedDirectory(string $directory): bool
    {
        // Never descend through a junction or symlink left by a tool. Children
        // have stopped before cleanup; only this operation's random root is owned.
        $real = realpath($directory);
        $normalize = static fn ($path) => strtolower(str_replace('\\', '/', $path));
        if (is_link($directory) || ($real !== false && $normalize($real) !== $normalize($directory))) {
            return @rmdir($directory) || @unlink($directory);
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            return false;
        }
        $clean = true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            $removed = is_dir($path) ? $this->removeOwnedDirectory($path) : @unlink($path);
            $clean = $removed && $clean;
        }

        return $clean && @rmdir($directory);
    }
}
