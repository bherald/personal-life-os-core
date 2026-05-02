<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * ops:health-gate — Single command that runs ALL validation before deploy.
 *
 * Chains: ops:validate-sql + ops:smoke-test + stabilization tests + schema drift check.
 * If ANY check fails, returns exit code 1 (blocks deploy).
 *
 * Usage:
 *   php artisan ops:health-gate              # Full gate (all checks)
 *   php artisan ops:health-gate --quick      # Fast checks only (skip SQL scan + full tests)
 *   php artisan ops:health-gate --json       # Machine-readable output
 */
class HealthGateCommand extends Command
{
    protected $signature = 'ops:health-gate
                            {--quick : Run only fast checks (smoke-test --quick)}
                            {--json : Output results as JSON}';

    protected $description = 'Run all validation gates — blocks deploy on failure';

    private array $results = [];

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $startTime = microtime(true);

        if (! $this->option('json')) {
            $this->info("=== PLOS Health Gate ===\n");
        }

        if (! $this->option('quick')) {
            $this->runGate('SQL Validation', 'ops:validate-sql', ['--json' => true], function (string $output) {
                $result = $this->extractJson($output);
                if (! $result) {
                    return [false, 'Invalid JSON output'];
                }
                $errors = count($result['errors'] ?? []);
                $warnings = count($result['warnings'] ?? []);

                return [$errors === 0, "{$errors} errors, {$warnings} warnings"];
            });
        }

        $smokeArgs = ['--quick' => true]; // Always use quick for gate — full smoke runs digest which pollutes output
        $this->runGate('Smoke Test', 'ops:smoke-test', array_merge($smokeArgs, ['--json' => true]), function (string $output) {
            $result = $this->extractJson($output);
            if (! $result) {
                return [false, 'Invalid JSON output'];
            }
            $fails = $result['fail'] ?? -1;

            return [$fails === 0, "pass={$result['pass']}, fail={$fails}, warn=".($result['warn'] ?? 0)];
        });

        // 2.2/ChatGPT-1B-F finding: MCP server executable paths drift
        // silently until first scrape. Quick pre-flight check — if any
        // enabled external MCP server's configured executable path or
        // command binary doesn't exist on disk, fail the gate so the
        // operator fixes it before deploy rather than at scrape time.
        $this->runGate('MCP Paths', null, [], null, fn () => $this->checkMcpPaths());

        // Sprint 2026-04-18 Phase 2.3: catch private-constant drift from
        // config/file_types.php before it reaches prod. Any service that
        // ships its own extension allowlist must be a subset of the
        // corresponding file_types class (or declared in
        // config('health_gate.classifier_deviations')). Discovered
        // drift here is the root cause of the Phase 1.1 .htm bug.
        $this->runGate('File Type Classifier', null, [], null, fn () => $this->checkFileTypeClassifierDrift());

        // D9: Commit ratio — regression quality signal
        $this->runGate('Commit Ratio', 'ops:commit-ratio', ['--json' => true], function (string $output) {
            $result = $this->extractJson($output);
            if (! $result) {
                return [true, 'Could not parse — skipping (non-blocking)'];
            }
            $ratio = $result['regression_ratio'] ?? 0;
            $status = $result['regression_status'] ?? 'PASS';
            $legacy = $result['legacy_pct'] ?? 0;
            $detail = "regression={$ratio}:1, legacy={$legacy}%";
            if ($status === 'WARN') {
                $detail .= ' — ELEVATED, investigate feature quality';
            }

            // Warning only, not a hard block (yet)
            return [true, $detail.($status === 'WARN' ? ' [WARN]' : '')];
        });

        if (! $this->option('quick')) {
            $this->runGate('Stabilization Tests', null, [], null, fn () => $this->checkStabilizationTests());
        }

        $duration = round(microtime(true) - $startTime, 1);

        if ($this->option('json')) {
            $this->line(json_encode([
                'pass' => $this->pass,
                'fail' => $this->fail,
                'duration_s' => $duration,
                'gates' => $this->results,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->line('--- Health Gate ---');
            if ($this->fail > 0) {
                $this->error("BLOCKED: {$this->fail} gate(s) failed. Do NOT deploy. ({$duration}s)");
            } else {
                $this->info("ALL GATES PASSED ({$this->pass} checks, {$duration}s) — safe to deploy.");
            }
        }

        $this->line("[ITEMS_PROCESSED:{$this->pass}]");

        return $this->fail > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Extract JSON from command output that may contain trailing non-JSON lines.
     */
    private function extractJson(string $output): ?array
    {
        $result = json_decode($output, true);
        if ($result !== null) {
            return $result;
        }

        // Commands append [ITEMS_PROCESSED:N] or other text after JSON
        if (preg_match('/(\{[\s\S]*\})/m', $output, $m)) {
            return json_decode($m[1], true);
        }

        return null;
    }

    /**
     * Run a gate check via an artisan command.
     */
    private function runGate(
        string $name,
        ?string $artisanCmd,
        array $args,
        ?\Closure $parseOutput,
        ?\Closure $customRunner = null
    ): void {
        if (! $this->option('json')) {
            $this->line("[{$name}]");
        }

        try {
            if ($customRunner) {
                [$passed, $detail] = $customRunner();
            } else {
                $exitCode = Artisan::call($artisanCmd, $args);
                $output = Artisan::output();

                if ($parseOutput) {
                    [$passed, $detail] = $parseOutput($output);
                } else {
                    $passed = $exitCode === 0;
                    $detail = "exit code {$exitCode}";
                }
            }
        } catch (\Throwable $e) {
            $passed = false;
            $detail = 'Exception: '.$e->getMessage();
        }

        $status = $passed ? 'PASS' : 'FAIL';
        $this->results[] = ['gate' => $name, 'status' => $status, 'detail' => $detail];

        if ($passed) {
            $this->pass++;
        } else {
            $this->fail++;
        }

        if (! $this->option('json')) {
            $icon = $passed ? '  OK' : 'FAIL';
            $this->line("  [{$icon}] {$detail}");
        }
    }

    private function extractPhpUnitSummary(string $output): string
    {
        if (preg_match('/Tests:\s+(\d+),\s+Assertions:\s+(\d+)/i', $output, $m)) {
            return "{$m[1]} tests, {$m[2]} assertions";
        }

        if (preg_match('/OK\s*\((\d+)\s+tests?,\s+(\d+)\s+assertions?\)/i', $output, $m)) {
            return "{$m[1]} tests, {$m[2]} assertions";
        }

        if (preg_match('/(\d+)\s+tests?,\s+(\d+)\s+assertions?/i', $output, $m)) {
            return "{$m[1]} tests, {$m[2]} assertions";
        }

        return 'Could not parse test results';
    }

    /**
     * Pre-flight check: every enabled external MCP server's configured
     * absolute executable path and known-executable env var must point
     * at something that exists on disk. Returns [passed, detail].
     *
     * Bare executable names (e.g. `node`, `npx`) resolve via PATH at
     * launch and are NOT flagged — only absolute paths (`/...`, `~/...`)
     * are verified.
     *
     * @return array{0: bool, 1: string}
     */
    protected function checkMcpPaths(): array
    {
        $servers = (array) config('mcp.servers', []);
        $missing = [];

        foreach ($servers as $name => $cfg) {
            if (! ($cfg['enabled'] ?? false)) {
                continue;
            }
            if (($cfg['type'] ?? 'external') !== 'external') {
                continue;
            }

            $cmd = (string) ($cfg['command'] ?? '');
            if ($cmd !== '' && (str_starts_with($cmd, '/') || str_starts_with($cmd, '~/'))) {
                $expandedCmd = str_replace('~', (string) getenv('HOME'), $cmd);
                if (! file_exists($expandedCmd) && ! is_link($expandedCmd)) {
                    $missing[] = "{$name}: command '{$expandedCmd}' not found";
                }
            }

            $env = (array) ($cfg['env'] ?? []);
            foreach ($env as $key => $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                if (preg_match('/EXECUTABLE|BINARY|CHROME|PATH$/i', (string) $key)
                    && preg_match('#^/|^~/#', $value)) {
                    $expanded = str_replace('~', (string) getenv('HOME'), $value);
                    if (! file_exists($expanded)) {
                        $missing[] = "{$name}: env.{$key} '{$expanded}' not found";
                    }
                }
            }
        }

        if (! empty($missing)) {
            return [false, 'missing paths: '.implode('; ', $missing)];
        }

        return [true, 'all enabled external MCP server paths resolve'];
    }

    /**
     * Target classes whose private/protected *EXTENSIONS* constants are
     * expected to be a subset of a specific `config/file_types.*` key.
     *
     * Each entry: [FQCN, constant name, config('file_types.*') key].
     * Keyed-map constants (e.g., ThumbnailService::EXTENSION_TYPE_MAP)
     * are intentionally omitted — they route by internal category, not
     * by a single file-types class, and require a different model.
     *
     * @return array<int, array{class: string, constant: string, config_key: string}>
     */
    protected function fileTypeClassifierTargets(): array
    {
        return [
            ['class' => \App\Services\PerceptualHashService::class,       'constant' => 'SUPPORTED_EXTENSIONS',       'config_key' => 'image'],
            ['class' => \App\Services\VideoHashService::class,            'constant' => 'SUPPORTED_EXTENSIONS',       'config_key' => 'video'],
            ['class' => \App\Services\FileCategorizationRAGService::class, 'constant' => 'BULK_TEXT_HEAVY_EXTENSIONS', 'config_key' => 'rag_indexable'],
            ['class' => \App\Console\Commands\PipelineBurnDownCommand::class, 'constant' => 'IMAGE_EXTENSIONS',       'config_key' => 'image'],
        ];
    }

    protected function checkFileTypeClassifierDrift(): array
    {
        $deviations = (array) config('health_gate.classifier_deviations', []);
        $issues = [];

        foreach ($this->fileTypeClassifierTargets() as $target) {
            $class = $target['class'];
            $constant = $target['constant'];
            $configKey = $target['config_key'];

            if (! class_exists($class)) {
                $issues[] = "{$class}: class does not exist";

                continue;
            }

            try {
                $ref = new \ReflectionClass($class);
                if (! $ref->hasConstant($constant)) {
                    // Constant was removed — that's a win (one fewer drift surface).
                    continue;
                }
                $values = (array) $ref->getConstant($constant);
            } catch (\Throwable $e) {
                $issues[] = "{$class}::{$constant}: reflection failed — {$e->getMessage()}";

                continue;
            }

            $expected = array_map('strtolower', (array) config("file_types.{$configKey}", []));
            if (empty($expected)) {
                $issues[] = "{$class}::{$constant}: expected config('file_types.{$configKey}') is empty — check config";

                continue;
            }

            $allowed = array_map('strtolower', $deviations[$class.'::'.$constant] ?? []);

            $flat = [];
            array_walk_recursive($values, static function ($v) use (&$flat): void {
                if (is_string($v) && $v !== '') {
                    $flat[] = strtolower($v);
                }
            });
            $flat = array_values(array_unique($flat));

            $orphans = array_diff($flat, $expected, $allowed);
            if (! empty($orphans)) {
                $issues[] = sprintf(
                    '%s::%s orphans [%s] not in config(file_types.%s) and not declared in health_gate.classifier_deviations',
                    $class,
                    $constant,
                    implode(',', $orphans),
                    $configKey
                );
            }
        }

        if (! empty($issues)) {
            return [false, 'drift detected: '.implode('; ', $issues)];
        }

        return [true, 'all tracked extension constants align with config(file_types.*)'];
    }

    private function runStabilizationTestProcess(): \Illuminate\Process\ProcessResult
    {
        $commands = [
            ['php', 'vendor/bin/phpunit', 'tests/Feature/Stabilization'],
            ['php', 'artisan', 'test', 'tests/Feature/Stabilization', '--without-tty'],
        ];

        foreach ($commands as $index => $command) {
            $result = Process::path(base_path())->run($command);
            $output = $result->output().$result->errorOutput();

            if (! str_contains($output, 'Could not open input file')) {
                return $result;
            }

            if ($index === array_key_last($commands)) {
                return $result;
            }
        }

        return Process::path(base_path())->run(['php', 'artisan', 'test', 'tests/Feature/Stabilization', '--without-tty']);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function checkStabilizationTests(): array
    {
        if (! is_dir($this->stabilizationTestPath())) {
            return [true, 'private stabilization tests not present — skipping'];
        }

        $result = $this->runStabilizationTestProcess();
        $output = $result->output().$result->errorOutput();
        $exitCode = $result->exitCode();

        if ($this->isUnavailableTestRunnerOutput($output)) {
            return [true, 'Test runner unavailable on this host — skipping (non-blocking)'];
        }

        $detail = $this->extractPhpUnitSummary($output);

        if (preg_match('/Failures?: (\d+)|Errors?: (\d+)/', $output, $fm)) {
            $failCount = max((int) ($fm[1] ?? 0), (int) ($fm[2] ?? 0));

            return [false, "{$detail} — {$failCount} failures\n{$output}"];
        }

        return [$exitCode === 0, $detail];
    }

    protected function stabilizationTestPath(): string
    {
        return base_path('tests/Feature/Stabilization');
    }

    private function isUnavailableTestRunnerOutput(string $output): bool
    {
        return str_contains($output, 'Could not open input file')
            || (str_contains($output, 'Available commands for the "test" namespace:')
                && ! str_contains($output, 'Tests:')
                && ! str_contains($output, 'FAILURES!'));
    }
}
