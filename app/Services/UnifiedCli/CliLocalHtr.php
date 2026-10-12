<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Explicit local CPU HTR; all model output remains unreviewed and unqueued. */
final class CliLocalHtr
{
    private bool $custodyLost = false;

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliArtifactStore $artifacts,
        private readonly CliHtrBindings $bindings,
        private readonly string $stateDirectory,
        private readonly ?Closure $keeper = null,
    ) {}

    public function extract(string $hash, string|array $frames, int $maxNewTokens): array
    {
        $this->custodyLost = false;
        $this->maintain();
        if ($maxNewTokens < 1 || $maxNewTokens > 4096 || ($frames !== 'all'
            && (! is_array($frames) || ! array_is_list($frames) || $frames === []))) {
            throw new RuntimeException('HTR requires explicit all frames or an ordered frame list, and 1 through 4096 output tokens per strip.');
        }
        if (is_array($frames)) {
            foreach ($frames as $frame) {
                if (! is_int($frame) || $frame < 1 || count(array_keys($frames, $frame, true)) !== 1) {
                    throw new RuntimeException('HTR frame selection requires unique positive one-based frame numbers.');
                }
            }
        }
        $session = $this->store->session($this->sessionId);
        $sources = array_values(array_filter($session['attachments'] ?? [], fn ($item) => ($item['sha256'] ?? null) === $hash));
        if ($sources === [] || ! in_array($sources[0]['mime_type'] ?? null,
            ['image/png', 'image/jpeg', 'image/tiff', 'image/bmp', 'image/webp', 'image/x-ms-bmp', 'image/x-portable-pixmap'], true)) {
            throw new RuntimeException('HTR requires an image artifact listed by /files in this session. No conversion was attempted.');
        }
        $selected = $this->bindings->select();
        $lockDirectory = sys_get_temp_dir().'/plos-cli-htr-'.posix_geteuid();
        if ((! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0700)) || is_link($lockDirectory)
            || fileowner($lockDirectory) !== posix_geteuid() || (fileperms($lockDirectory) & 0077) !== 0) {
            throw new RuntimeException('Private HTR host lock directory is unavailable.');
        }
        // Serialize this operator's local HTR even across separate CLI states
        // and multiple compute rows describing the same physical computer.
        $key = substr($selected['runtime']['machine_id_sha256'], 0, 36);
        $lock = new CliSessionLock($lockDirectory, $key);
        try {
            $this->bindings->verify($selected, $this->maintain(...));
            $this->health($selected['runtime']);

            return $this->artifacts->inspect($hash, function ($input) use ($hash, $selected, $frames, $maxNewTokens): array {
                $preflight = $this->run($selected['runtime'], $input, [], true);
                $this->store->recordEvent($this->sessionId, 'htr_preflight', ['source_sha256' => $hash,
                    'compute_instance' => $selected['compute_instance'], 'binding_sha256' => $selected['binding_sha256'],
                    'stdout' => $this->artifacts->captureBytes($preflight['stdout']),
                    'stderr' => $this->artifacts->captureBytes($preflight['stderr']), 'exit_code' => $preflight['exit_code'],
                    'interrupted' => $preflight['interrupted'], 'custody_lost' => $this->custodyLost]);
                if ($preflight['exit_code'] !== 0 || $preflight['interrupted']) {
                    throw new RuntimeException('HTR isolated image preflight failed; complete diagnostics were retained. No model was launched.');
                }
                $metadata = json_decode($preflight['stdout'], true, flags: JSON_THROW_ON_ERROR);
                $count = $metadata['source_frame_count'] ?? null;
                if (! is_int($count) || $count < 1 || (is_array($frames) && max($frames) > $count)) {
                    throw new RuntimeException('HTR selection exceeds the independently inspected source frame count.');
                }
                // Recheck after image decoding, immediately before model launch.
                $this->bindings->verify($selected, $this->maintain(...));
                $this->health($selected['runtime']);
                $expected = ['model' => '/model', 'device' => 'cpu', 'max_new_tokens' => $maxNewTokens,
                    'eos_token_ids' => $selected['runtime']['eos_token_ids'], 'binding_sha256' => $selected['binding_sha256'],
                    'compute_instance' => $selected['compute_instance'], 'frame_selection' => $frames, 'source_frame_count' => $count];
                $this->store->recordEvent($this->sessionId, 'htr_runtime_admission', ['source_sha256' => $hash,
                    'binding_sha256' => $selected['binding_sha256'], 'registry_sha256' => $selected['registry_sha256'],
                    'compute_instance' => $selected['compute_instance'], 'device' => 'cpu', 'network' => 'offline',
                    'execution_location_verified' => true, 'general_agent_admitted' => false]);
                $result = $this->run($selected['runtime'], $input, ['image_path' => '/input/source',
                    'cli' => ['schema' => 'plos.cli.htr.v2', 'model_path' => '/model', 'device' => 'cpu',
                        'max_new_tokens' => $maxNewTokens, 'frame_selection' => $frames]], false);
                $integrityError = null;
                try {
                    $this->maintain();
                    $this->bindings->verify($selected, $this->maintain(...));
                    $this->health($selected['runtime']);
                } catch (\Throwable) {
                    $integrityError = 'HTR binding, assets, host health or engine lease changed during execution.';
                }
                // Transport must be retained even when post-run integrity fails.
                $receipt = (new CliHtrResult($this->store, $this->sessionId, $this->artifacts))->retain($hash,
                    $result['stdout'], $result['stderr'], $result['exit_code'], $result['interrupted'] || $integrityError !== null, $expected, $this->custodyLost);
                $this->store->recordEvent($this->sessionId, 'htr_runtime_completion', ['capture_id' => $receipt['capture_id'],
                    'binding_sha256' => $selected['binding_sha256'], 'integrity_error' => $integrityError,
                    'custody_lost' => $this->custodyLost,
                    'execution_complete' => $receipt['execution_complete'], 'quality_status' => 'unreviewed']);

                return $receipt;
            });
        } finally {
            $lock->release();
        }
    }

    private function health(array $binding): void
    {
        $this->maintain();
        foreach ($binding['required_services'] as $unit) {
            $process = new Process(['/usr/bin/systemctl', 'is-active', '--quiet', $unit], '/', $this->environment(), null, 5);
            if (! (new CliMaintainedProcess)->run($process, $this->maintain(...))['success']) {
                throw new RuntimeException('A required host service is not active; HTR cannot proceed.');
            }
        }
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $this->maintain();
            foreach (explode("\0", @file_get_contents($path) ?: '') as $argument) {
                if (str_ends_with($argument, '/fullbackup.sh') || str_ends_with($argument, '/htr_transcribe.py')) {
                    throw new RuntimeException('Backup or another HTR process is active; no parallel HTR launch is allowed.');
                }
            }
        }
    }

    private function run(array $binding, string $input, array $request, bool $preflight): array
    {
        $uid = posix_geteuid();
        $gid = posix_getegid();
        if ($uid === 0) {
            throw new RuntimeException('Launch the CLI as its ordinary operator account; root model execution is refused.');
        }
        $directory = $this->stateDirectory.'/htr-work-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot prepare private HTR work directory.');
        }
        try {
            file_put_contents($directory.'/passwd', "operator:x:{$uid}:{$gid}:CLI:/tmp:/usr/sbin/nologin\n");
            file_put_contents($directory.'/group', "operator:x:{$gid}:\n");
            $timeout = $preflight ? min(30, $binding['timeout_seconds']) : $binding['timeout_seconds'];
            $args = $binding['sandbox'] === 'sudo_drop' ? ['/usr/bin/sudo', '-n'] : [];
            array_push($args, '/usr/bin/timeout', '--signal=TERM', '--kill-after=5', (string) $timeout,
                $binding['sandbox'] === 'sudo_drop' ? '/usr/bin/bwrap' : CliLinuxSandbox::executable(),
                '--die-with-parent', '--new-session', '--unshare-net', '--unshare-pid', '--unshare-ipc', '--unshare-uts');
            if ($binding['sandbox'] === 'user') {
                array_push($args, '--cap-drop', 'ALL');
            }
            array_push($args, '--ro-bind', '/usr', '/usr');
            foreach (['/lib', '/lib64', '/etc/ld.so.cache', '/etc/fonts'] as $path) {
                if (file_exists($path)) {
                    array_push($args, '--ro-bind', $path, $path);
                }
            }
            array_push($args, '--ro-bind', $binding['runtime_root'], '/runtime', '--ro-bind', $binding['site_packages'], '/runtime-deps',
                '--dev', '/dev', '--proc', '/proc', '--perms', '1777', '--tmpfs', '/tmp', '--perms', '0755', '--dir', '/model');
            foreach ($binding['model_files'] as $name => $file) {
                array_push($args, '--ro-bind', $file['path'], '/model/'.$name);
            }
            array_push($args, '--ro-bind', $input, '/input/source', '--ro-bind', $binding['worker_path'], '/worker.py',
                '--ro-bind', $directory.'/passwd', '/etc/passwd', '--ro-bind', $directory.'/group', '/etc/group',
                '--chdir', '/tmp', '--', '/usr/bin/setpriv');
            if ($binding['sandbox'] === 'sudo_drop') {
                array_push($args, '--reuid', (string) $uid, '--regid', (string) $gid, '--clear-groups', '--bounding-set=-all');
            }
            array_push($args, '--no-new-privs', '/usr/bin/env', '-i');
            foreach ($this->environment() as $key => $value) {
                if (is_string($value)) {
                    $args[] = $key.'='.$value;
                }
            }
            $script = 'import os,json,runpy;from pathlib import Path;'.
                "caps={s.split(':')[0]:s.split(':')[1].strip() for s in Path('/proc/self/status').read_text().splitlines() if s.startswith(('CapEff:','CapBnd:','NoNewPrivs:'))};".
                "assert os.getuid()=={$uid} and os.getgid()=={$gid} and int(caps['CapEff'],16)==0 and int(caps['CapBnd'],16)==0 and caps['NoNewPrivs']=='1';".
                'assert os.readlink("/proc/self/ns/net")!='.json_encode(readlink('/proc/self/ns/net'), JSON_THROW_ON_ERROR).';';
            $script .= $preflight ? "from PIL import Image;image=Image.open('/input/source');print(json.dumps({'source_frame_count':image.n_frames}));image.close()"
                : "runpy.run_path('/worker.py',run_name='__main__')";
            array_push($args, '/runtime/bin/'.basename($binding['python_path']), '-c', $script);
            $process = new Process($args, '/', $this->environment(), json_encode($request, JSON_THROW_ON_ERROR), $timeout + 10);
            $result = (new CliMaintainedProcess)->run($process, $this->maintain(...));
            $result['interrupted'] = $result['interrupted'] || in_array($result['exit_code'], [124, 137], true);

            return $result;
        } finally {
            foreach (['passwd', 'group'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }

    private function maintain(): void
    {
        if ($this->custodyLost) {
            throw new RuntimeException('HTR engine lease ownership was lost; the action was interrupted.');
        }
        try {
            $this->keeper?->__invoke();
        } catch (\Throwable $error) {
            $this->custodyLost = true;
            throw $error;
        }
    }

    private function environment(): array
    {
        $environment = array_fill_keys(array_filter(array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv()))), is_string(...)), false);

        return array_replace($environment, ['USER' => 'operator', 'LOGNAME' => 'operator', 'PATH' => '/usr/bin:/bin', 'HOME' => '/tmp',
            'LANG' => 'C.UTF-8', 'PYTHONPATH' => '/runtime-deps', 'CUDA_VISIBLE_DEVICES' => '', 'HF_HUB_OFFLINE' => '1',
            'TRANSFORMERS_OFFLINE' => '1', 'HF_HUB_DISABLE_TELEMETRY' => '1', 'TOKENIZERS_PARALLELISM' => 'false',
            'OMP_NUM_THREADS' => '1', 'OMP_THREAD_LIMIT' => '1', 'MKL_NUM_THREADS' => '1', 'NUMEXPR_NUM_THREADS' => '1', 'PYTHONDONTWRITEBYTECODE' => '1']);
    }
}
