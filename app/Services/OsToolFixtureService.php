<?php

namespace App\Services;

use App\Services\UnifiedCli\CliLinuxSandbox;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** First source-safety qualification; no caller paths, URLs, rules or shell code. */
class OsToolFixtureService
{
    public const FIXTURES = [
        'risky' => "#!/bin/sh\nprintf '%s\\n' \$1\n",
        'fixed' => "#!/bin/sh\nprintf '%s\\n' \"\$1\"\n",
        'clean' => "#!/bin/sh\nprintf '%s\\n' 'synthetic fixture'\n",
    ];

    private const LIBRARIES = [
        '/lib/x86_64-linux-gnu/libgmp.so.10', '/lib/x86_64-linux-gnu/libc.so.6',
        '/lib/x86_64-linux-gnu/libm.so.6', '/lib/x86_64-linux-gnu/libffi.so.8',
        '/lib64/ld-linux-x86-64.so.2',
    ];

    public function __construct(private readonly ?OsToolDiscoveryService $discovery = null) {}

    public function checkFixture(string $fixture, string $expected_identity, ?string $expected_environment = null, ?string $expected_evidence = null): array
    {
        if (! isset(self::FIXTURES[$fixture]) || ! preg_match('/^[a-f0-9]{64}$/D', $expected_identity)) {
            throw new RuntimeException('Only named synthetic fixtures and an observed executable identity are accepted.');
        }
        $tool = '/usr/bin/shellcheck';
        $identity = OsToolDiscoveryService::identity($tool);
        if ($identity === null || ! hash_equals(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), $expected_identity)) {
            throw new RuntimeException('Executable identity changed or is absent; observe and review it again.');
        }
        try {
            $review = ($this->discovery ?? new OsToolDiscoveryService)->assertReviewed(
                'shellcheck', $expected_environment ?? '', $expected_evidence ?? '',
            );
        } catch (RuntimeException) {
            // No subprocess has started. Preserve this known denial as a typed
            // failure instead of making the shared dispatcher infer an unknown outcome.
            return ['success' => false, 'status' => 'review_required', 'complete' => false,
                'reason' => 'review_evidence_changed_incomplete_or_missing', 'findings' => [],
                'version_probe_passed' => false, 'scanner_started' => false,
                'execution_authorized_for_other_inputs' => false];
        }
        $reviewedPath = array_filter($review['evidence_binding']['executables'] ?? [],
            fn ($entry) => ($entry['canonical_path_sha256'] ?? null) === hash('sha256', $identity['resolved'])
                && ($entry['content_sha256'] ?? null) === $identity['sha256']);
        if ($reviewedPath === []) {
            throw new RuntimeException('Reviewed executable does not match this adapter runtime. Review the exact installed adapter binary.');
        }
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            throw new RuntimeException('This fixture adapter is qualified only for Linux x86_64.');
        }
        $sandbox = CliLinuxSandbox::executable();
        $runtime = [];
        foreach ([$tool, $sandbox, '/usr/bin/prlimit', ...self::LIBRARIES] as $path) {
            if (! OsToolDiscoveryService::protectedPath($path) || ! is_readable($path)) {
                throw new RuntimeException('A protected fixture runtime dependency is unavailable.');
            }
            $runtime[$path] = OsToolDiscoveryService::identity($path);
        }
        $environment = array_fill_keys(array_keys(getenv()), false) + ['LC_ALL' => 'C'];
        $environment['LC_ALL'] = 'C';
        if ($runtime[$tool] !== $identity) {
            return ['success' => false, 'status' => 'review_required', 'complete' => false,
                'reason' => 'binary_changed_after_review', 'findings' => [],
                'version_probe_passed' => false, 'scanner_started' => false,
                'execution_authorized_for_other_inputs' => false];
        }
        $this->assertRuntime($runtime);
        $version = new Process([$tool, '--version'], '/', $environment);
        $version->setTimeout(3)->run();
        if (! $version->isSuccessful() || ! preg_match('/^version: (0\.9\.0)$/m', $version->getOutput(), $match)) {
            throw new RuntimeException('ShellCheck runtime version is unqualified; no fallback or installation attempted.');
        }
        $base = storage_path('app/private/os-tool-discovery');
        if (is_link($base) || (! is_dir($base) && ! mkdir($base, 0700, true))) {
            throw new RuntimeException('Private evidence storage is unavailable.');
        }
        $id = bin2hex(random_bytes(16));
        $directory = $base.'/'.$id;
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create private fixture evidence.');
        }
        $this->writeEvidence($directory.'/input.sh', self::FIXTURES[$fixture]);
        $command = ['/usr/bin/prlimit', '--as=536870912', '--cpu=5', '--fsize=262144', '--nofile=64', '--',
            $sandbox, '--unshare-all', '--die-with-parent', '--new-session', '--clearenv',
            '--setenv', 'LC_ALL', 'C', '--dir', '/lib', '--dir', '/lib/x86_64-linux-gnu', '--dir', '/lib64'];
        foreach (self::LIBRARIES as $library) {
            $command = [...$command, '--ro-bind', realpath($library), $library];
        }
        $command = [...$command, '--ro-bind', realpath($tool), '/tool', '--dir', '/input',
            '--ro-bind', $directory.'/input.sh', '/input/check.sh', '--chdir', '/input',
            '--', '/tool', '--norc', '--format=json1', '--shell=sh', '/input/check.sh'];
        // Recheck immediately before launching; discovery never serves as permission.
        ($this->discovery ?? new OsToolDiscoveryService)->assertReviewed('shellcheck', $expected_environment, $expected_evidence);
        $this->assertRuntime($runtime);
        $process = new Process($command, '/', $environment);
        $process->setTimeout(8);
        $streams = ['out' => '', 'err' => ''];
        $reason = null;
        $started = microtime(true);
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $streams['out'] .= $process->getIncrementalOutput();
                $streams['err'] .= $process->getIncrementalErrorOutput();
                if (strlen($streams['out']) + strlen($streams['err']) > 262144) {
                    $reason = 'output_limit';
                    $process->stop(0.1);
                    break;
                }
                usleep(10000);
            }
        } catch (ProcessTimedOutException) {
            $reason = 'timeout';
        } catch (\Throwable) {
            $reason = 'process_failure';
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.1);
            }
            if ($process->isStarted()) {
                $streams['out'] .= $process->getIncrementalOutput();
                $streams['err'] .= $process->getIncrementalErrorOutput();
            }
        }
        if (strlen($streams['out']) + strlen($streams['err']) > 262144) {
            $reason = 'output_limit';
        }
        try {
            $this->assertRuntime($runtime);
        } catch (RuntimeException) {
            $reason = 'runtime_identity_changed';
        }
        $outcome = $reason === null ? self::parse($process->getExitCode(), $streams['out'], $streams['err'])
            : ['success' => false, 'status' => 'failed', 'reason' => $reason, 'complete' => false, 'findings' => []];
        $expected = $fixture === 'risky' ? [2086] : [];
        $actual = array_column($outcome['findings'], 'code');
        $outcome['fixture_probe_passed'] = $outcome['success'] && $actual === $expected;
        if ($outcome['success'] && ! $outcome['fixture_probe_passed']) {
            $outcome = array_replace($outcome, ['success' => false, 'status' => 'failed', 'reason' => 'fixture_expectation_mismatch']);
        }
        $artifacts = [];
        foreach (['stdout' => $streams['out'], 'stderr' => $streams['err'], 'version' => $version->getOutput()] as $name => $bytes) {
            // This exact capped artifact is explicitly marked incomplete on overflow.
            $bytes = substr($bytes, 0, 262144);
            $this->writeEvidence($directory.'/'.$name.'.txt', $bytes);
            $artifacts[$name] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        }
        $result = $outcome + ['schema' => 'plos.os-tools.fixture.v1', 'fixture' => $fixture,
            'scope' => 'one_synthetic_shell_script_only', 'files_selected' => 1,
            'files_checked' => $outcome['complete'] ? 1 : 0, 'omitted_files' => 0,
            'version' => $match[1], 'version_probe_passed' => true, 'identity_sha256' => $expected_identity,
            'review_evidence_sha256' => $expected_evidence, 'execution_environment' => $review['execution_environment'],
            'runtime_identity_sha256' => hash('sha256', json_encode($runtime, JSON_THROW_ON_ERROR)),
            'input_sha256' => hash('sha256', self::FIXTURES[$fixture]), 'evidence_id' => $id,
            'artifacts' => $artifacts, 'exit_code' => $process->getExitCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'network' => 'unshared_namespace', 'raw_evidence_complete' => $reason !== 'output_limit',
            'observed_at' => gmdate(DATE_ATOM), 'execution_authorized_for_other_inputs' => false];
        $this->writeEvidence($directory.'/receipt.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        return $result;
    }

    public static function parse(?int $exit, string $stdout, string $stderr): array
    {
        $failure = ['success' => false, 'status' => 'failed', 'complete' => false, 'findings' => [], 'reason' => 'invalid_scanner_result'];
        if (! in_array($exit, [0, 1], true) || trim($stderr) !== '') {
            return $failure;
        }
        try {
            $object = json_decode($stdout, false, flags: JSON_THROW_ON_ERROR);
            if (! is_object($object) || ! is_array($object->comments ?? null)) {
                return $failure;
            }
            $data = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $failure;
        }
        if (! is_array($data) || array_keys($data) !== ['comments'] || ! is_array($data['comments']) || ! array_is_list($data['comments'])) {
            return $failure;
        }
        foreach ($data['comments'] as $finding) {
            if (! is_array($finding) || ($finding['file'] ?? null) !== '/input/check.sh'
                || ! is_int($finding['code'] ?? null) || ! is_int($finding['line'] ?? null) || $finding['line'] < 1
                || ! is_int($finding['column'] ?? null) || $finding['column'] < 1
                || ! is_string($finding['message'] ?? null)
                || ! in_array($finding['level'] ?? null, ['error', 'warning', 'info', 'style'], true)
                || in_array($finding['code'], [1009, 1072, 1073, 1091], true)) {
                return $failure;
            }
        }
        if (($exit === 0) !== ($data['comments'] === [])) {
            return $failure;
        }

        return ['success' => true, 'status' => $exit === 0 ? 'complete_clean' : 'complete_findings',
            'complete' => true, 'findings' => $data['comments']];
    }

    private function assertRuntime(array $runtime): void
    {
        foreach ($runtime as $path => $identity) {
            if (OsToolDiscoveryService::identity($path) !== $identity || ! OsToolDiscoveryService::protectedPath($path)) {
                throw new RuntimeException('Fixture runtime identity changed.');
            }
        }
    }

    private function writeEvidence(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path, 0600)
            || hash_file('sha256', $path) !== hash('sha256', $bytes)) {
            throw new RuntimeException('Private evidence could not be retained and verified; no successful receipt issued.');
        }
    }
}
