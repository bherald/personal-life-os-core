<?php

namespace App\Services;

use App\Services\UnifiedCli\CliToolOutcomeUnknown;
use App\Services\UnifiedCli\CliWindowsActionProcess;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** One named synthetic syntax check. No supplied code, paths, commands or network. */
class OsToolWindowsPhpFixtureService
{
    public const FILES = ['clean' => 'clean.php', 'invalid' => 'invalid.php.txt'];

    public function __construct(private readonly ?OsToolDiscoveryService $discovery = null) {}

    public function checkFixture(string $fixture, string $expected_environment, string $expected_evidence): array
    {
        if (! isset(self::FILES[$fixture])) {
            throw new RuntimeException('Choose one registered synthetic PHP syntax fixture.');
        }
        $discovery = $this->discovery ?? new OsToolDiscoveryService;
        try {
            $review = $discovery->assertReviewed('php', $expected_environment, $expected_evidence);
            $definition = $discovery->catalog()['php'];
            $executables = array_values(array_filter($definition['windows_review_files'] ?? [], fn ($file) => ($file['kind'] ?? null) === 'executable'));
            if (PHP_OS_FAMILY !== 'Windows' || count($executables) !== 1) {
                throw new RuntimeException('Only the Windows selected-file adapter is available.');
            }
            $php = $executables[0]['path'];
            $input = realpath(base_path('resources/os-tools/php-syntax-fixtures/'.self::FILES[$fixture]));
            $identity = [];
            foreach ([$php, $input] as $path) {
                if (! is_string($path) || ! is_file($path) || is_link($path)) {
                    throw new RuntimeException('Selected syntax runtime is absent.');
                }
                $hash = hash_file('sha256', $path);
                $pathHash = hash('sha256', strtolower(str_replace('/', '\\', $path)));
                if (! array_filter($review['evidence_binding']['selected_files'] ?? [], fn ($file) => $file['path_sha256'] === $pathHash && $file['sha256'] === $hash)) {
                    throw new RuntimeException('Selected syntax runtime differs from reviewed evidence.');
                }
                $identity[$path] = $hash;
            }
        } catch (RuntimeException) {
            return ['success' => false, 'status' => 'review_required', 'complete' => false,
                'reason' => 'review_evidence_changed_incomplete_or_missing', 'runtime_started' => false];
        }
        // -n disables startup ini/auto-prepend; -l parses without executing source.
        // Literal argv, existing Symfony Windows process support, no shell.
        $environment = array_fill_keys(array_keys(getenv()), false);
        $environment['SystemRoot'] = getenv('SystemRoot');
        $process = new Process([$php, '-n', '-l', $input], dirname($input), $environment, timeout: 5);
        $started = microtime(true);
        try {
            if (CliWindowsActionProcess::available()) {
                $supervised = CliWindowsActionProcess::run([$php, '-n', '-l', $input], dirname($input), 5000);
            } else {
                $process->run();
            }
        } catch (ProcessTimedOutException) {
            throw new CliToolOutcomeUnknown('Synthetic syntax process deadline or cleanup requires reconciliation; no automatic replay.');
        }
        foreach ($identity as $path => $hash) {
            if (! hash_equals($hash, (string) @hash_file('sha256', $path))) {
                throw new CliToolOutcomeUnknown('Synthetic syntax runtime changed during execution; reconcile its outcome before another action.');
            }
        }

        $exit = isset($supervised) ? $supervised['exit_code'] : $process->getExitCode();
        $stdout = $supervised['stdout'] ?? $process->getOutput();
        $stderr = $supervised['stderr'] ?? $process->getErrorOutput();

        return self::interpret($fixture, $exit, $stdout, $stderr)
            + ['schema' => 'plos.os-tools.php-syntax-fixture.v1', 'fixture' => $fixture,
                'execution_environment' => $review['execution_environment'], 'review_evidence_sha256' => $expected_evidence,
                'input_sha256' => $identity[$input], 'runtime_sha256' => $identity[$php],
                'stdout' => $stdout, 'stderr' => $stderr,
                'exit_code' => $exit, 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'scope' => 'one_fixed_synthetic_php_file_syntax_only', 'fixture_source_executed' => false,
                'network_isolation' => isset($supervised) ? 'existing_offline_windows_sandbox' : 'not_claimed_fixed_parser_operation_only', 'runtime_started' => true,
                'automatic_retry_allowed' => false, 'customer_security_assessed' => false];
    }

    public static function interpret(string $fixture, ?int $exit, string $stdout, string $stderr): array
    {
        $clean = $exit === 0 && trim($stderr) === '' && preg_match('/\ANo syntax errors detected in [^\r\n]+\r?\n?\z/', $stdout) === 1;
        $invalid = $exit === 255 && str_contains($stdout.$stderr, 'Parse error:') && str_contains($stdout.$stderr, 'syntax error')
            && str_contains($stdout.$stderr, 'Errors parsing ');
        $expected = ($fixture === 'clean' && $clean) || ($fixture === 'invalid' && $invalid);

        return ['success' => $expected, 'complete' => $expected, 'fixture_probe_passed' => $expected,
            'status' => $expected ? 'completed' : 'unrecognized_or_unexpected_result',
            'syntax_valid' => $expected ? $clean : null, 'findings' => $expected && $invalid ? [['type' => 'synthetic_php_syntax_error']] : []];
    }
}
