<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class OfflineCodingEvalWorkspaceService
{
    private const DOCKER_IMAGE_CONFIG_KEY = 'ai.offline_coding_eval_verifier_docker_image';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function dockerVerifierImageIdentity(): string
    {
        $image = trim((string) $this->systemConfig->get(self::DOCKER_IMAGE_CONFIG_KEY, ''));

        return preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image) === 1
            ? $image
            : 'unavailable';
    }

    /**
     * @param  array<string,string>  $files
     */
    public function materializeStarterFiles(string $workspace, array $files): void
    {
        foreach ($files as $relativePath => $contents) {
            $relativePath = str_replace('\\', '/', (string) $relativePath);
            if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '../')) {
                throw new RuntimeException('Invalid scratch fixture path.');
            }

            $path = $workspace.DIRECTORY_SEPARATOR.$relativePath;
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create scratch fixture directory.');
            }
            if (file_put_contents($path, (string) $contents) === false) {
                throw new RuntimeException('Unable to write scratch fixture file.');
            }
        }
    }

    /**
     * @return array<string,string>
     */
    public function snapshot(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return [];
        }

        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            $relativePath = str_replace('\\', '/', $iterator->getSubPathname());
            if ($item->isLink()) {
                $target = readlink($item->getPathname());
                $snapshot[$relativePath] = 'link:'.hash('sha256', is_string($target) ? $target : 'unreadable');

                continue;
            }
            if (! $item->isFile()) {
                continue;
            }

            $hash = hash_file('sha256', $item->getPathname());
            if (is_string($hash)) {
                $snapshot[$relativePath] = $hash;
            }
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string,string>  $before
     * @param  array<string,string>  $after
     * @return list<string>
     */
    public function changedPaths(array $before, array $after): array
    {
        $paths = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        $changed = array_values(array_filter(
            $paths,
            static fn (string $path): bool => ($before[$path] ?? null) !== ($after[$path] ?? null)
        ));
        sort($changed);

        return $changed;
    }

    /**
     * @param  list<string>  $command
     * @return array{name:string,passed:bool,detail:string}
     */
    public function runIsolatedVerifier(
        string $name,
        array $command,
        string $workspace,
        string $expected,
        string $detail
    ): array {
        $bubblewrap = (string) config('offline_coding_eval.bubblewrap_path', '/usr/bin/bwrap');
        if (is_executable($bubblewrap)) {
            $sandboxedCommand = [
                $bubblewrap,
                '--ro-bind', '/', '/',
                '--dev', '/dev',
                '--proc', '/proc',
                '--tmpfs', '/tmp',
                '--unshare-net',
                '--die-with-parent',
                '--new-session',
                '--chdir', $workspace,
                ...$command,
            ];
            $result = $this->runVerifierProcess($sandboxedCommand, $workspace, $name, $expected, $detail);
            if (($result['name'] ?? '') !== 'verifier_isolation') {
                return $result;
            }
        }

        return $this->runDockerVerifier($name, $command, $workspace, $expected, $detail);
    }

    /**
     * @param  list<string>  $command
     * @return array{name:string,passed:bool,detail:string}
     */
    private function runDockerVerifier(
        string $name,
        array $command,
        string $workspace,
        string $expected,
        string $detail
    ): array {
        $sandboxedCommand = $this->dockerVerifierCommand($command, $workspace);
        if ($sandboxedCommand === null) {
            return [
                'name' => 'verifier_isolation',
                'passed' => false,
                'detail' => 'No usable networkless verifier sandbox is configured.',
            ];
        }

        return $this->runVerifierProcess($sandboxedCommand, $workspace, $name, $expected, $detail, 125);
    }

    /**
     * @param  list<string>  $command
     * @return list<string>|null
     */
    private function dockerVerifierCommand(array $command, string $workspace): ?array
    {
        $docker = (string) config('offline_coding_eval.docker_path', '/usr/bin/docker');
        $image = $this->dockerVerifierImageIdentity();
        $workspace = realpath($workspace);
        if (! is_executable($docker)
            || $image === 'unavailable'
            || ! is_string($workspace)
            || ! is_dir($workspace)
            || ($command[0] ?? null) !== 'php') {
            return null;
        }

        $uid = function_exists('posix_getuid') ? posix_getuid() : 65534;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 65534;

        return [
            $docker,
            'run',
            '--rm',
            '--pull=never',
            '--network=none',
            '--read-only',
            '--cap-drop=ALL',
            '--security-opt=no-new-privileges',
            '--pids-limit=64',
            '--memory=256m',
            '--cpus=1',
            '--user='.$uid.':'.$gid,
            '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16m',
            '--mount=type=bind,source='.$workspace.',target=/workspace,readonly',
            '--workdir=/workspace',
            '--entrypoint=php',
            $image,
            ...array_slice($command, 1),
        ];
    }

    /**
     * @param  list<string>  $command
     * @return array{name:string,passed:bool,detail:string}
     */
    private function runVerifierProcess(
        array $command,
        string $workspace,
        string $name,
        string $expected,
        string $detail,
        ?int $isolationFailureExitCode = null
    ): array {
        $process = new Process($command, $workspace);
        $process->setTimeout(30);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if ((! $process->isSuccessful() && str_contains($output, 'bwrap:'))
            || ($isolationFailureExitCode !== null && $process->getExitCode() === $isolationFailureExitCode)) {
            return [
                'name' => 'verifier_isolation',
                'passed' => false,
                'detail' => 'The verifier sandbox could not be established.',
            ];
        }

        return [
            'name' => $name,
            'passed' => $process->isSuccessful()
                && ($expected === '' || str_contains(mb_strtolower($output), mb_strtolower($expected))),
            'detail' => $detail,
        ];
    }
}
