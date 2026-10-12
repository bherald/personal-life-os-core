<?php

namespace App\Console\Commands;

use App\Services\CodexExecConnectorService;
use App\Services\OfflineCodingEvalReceiptService;
use App\Services\OfflineCodingEvalWorkspaceService;
use App\Services\OfflineCodingRouteService;
use App\Services\Ops\AgentActionSeverityService;
use App\Services\Ops\AgentReliabilityProfileService;
use App\Services\Ops\AgentTrajectoryAssessmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CodexOfflineEvalCommand extends Command
{
    public const TRANSIENT_FAULT_RECOVERY_CALL_LIMIT = 5;

    private const RELIABILITY_CONDITIONS = [
        'nominal',
        'prompt_perturbation',
        'environment_perturbation',
        'fault_injection',
    ];

    protected $signature = 'codex:offline-eval
        {--json : Emit machine-readable JSON}
        {--execute : Actually run local Codex. Default only lists planned cases}
        {--case=* : Case to run; repeatable. Defaults to all}
        {--profile= : Optional profile override; must match the admitted offline coding route}
        {--model= : Optional model override; must be installed on the admitted target instance}
        {--effort= : Optional reasoning effort override}
        {--timeout=900 : Timeout in seconds per case}
        {--repeat=1 : Independent trials per case (1-10); protected promotion requires 3}
        {--reliability-condition=* : Additional protected conditions: prompt_perturbation, environment_perturbation, fault_injection}
        {--receipt= : Write a content-free integrity-bound eval receipt after execution}
        {--keep-workspaces : Keep scratch eval workspaces after successful runs}';

    protected $description = 'Run repeatable evals for the local/offline Codex programming profile';

    public function handle(
        CodexExecConnectorService $connector,
        OfflineCodingEvalWorkspaceService $workspaceService,
        AgentTrajectoryAssessmentService $trajectoryAssessment,
        AgentActionSeverityService $actionSeverity,
        AgentReliabilityProfileService $reliabilityProfile,
        OfflineCodingEvalReceiptService $receipts,
        OfflineCodingRouteService $offlineRoute,
    ): int {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 1) {
            return $this->failWithPayload('Invalid --timeout. Use a positive integer.');
        }
        $repeat = filter_var($this->option('repeat'), FILTER_VALIDATE_INT);
        if ($repeat === false || $repeat < 1 || $repeat > 10) {
            return $this->failWithPayload('Invalid --repeat. Use an integer from 1 through 10.');
        }
        $reliabilityConditions = $this->reliabilityConditions((array) $this->option('reliability-condition'));
        if ($reliabilityConditions === null) {
            return $this->failWithPayload(
                'Invalid --reliability-condition. Use prompt_perturbation, environment_perturbation, or fault_injection.'
            );
        }

        $cases = $this->selectCases((array) $this->option('case'));
        if ($cases === []) {
            return $this->failWithPayload('No matching offline Codex eval cases.');
        }
        if (count($reliabilityConditions) > 1 && collect($cases)->contains(
            static fn (array $case): bool => ! isset($case['verify_php'])
        )) {
            return $this->failWithPayload(
                'Additional reliability conditions require protected evaluator-owned cases.'
            );
        }
        $route = $offlineRoute->resolve(
            $this->optionalString('profile'),
            $this->optionalString('model'),
            'coding',
            $this->optionalString('effort'),
        );
        if (($route['execution_allowed'] ?? false) !== true) {
            return $this->emitPayload([
                'status' => 'blocked',
                'execute' => false,
                'error' => 'Offline coding route is blocked: '.($route['reason'] ?? 'unknown'),
                'offline_route' => $route,
            ], self::FAILURE);
        }
        $productConfiguration = $this->productConfiguration($workspaceService, $route);
        $receiptPath = trim((string) ($this->option('receipt') ?: ''));

        if ($receiptPath !== '' && ! (bool) $this->option('execute')) {
            return $this->failWithPayload('--receipt requires --execute. Planning remains read-only.');
        }

        if (! (bool) $this->option('execute')) {
            $payload = [
                'status' => 'planned',
                'execute' => false,
                'repeat' => $repeat,
                'reliability_conditions' => $reliabilityConditions,
                'product_configuration' => $productConfiguration,
                'offline_route' => $route,
                'cases' => array_map(
                    fn (array $case): array => $this->casePlan($case, $repeat, $reliabilityConditions),
                    $cases
                ),
            ];

            return $this->emitPayload($payload, self::SUCCESS);
        }

        $runId = 'offline-codex-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $results = [];
        foreach ($cases as $case) {
            $trials = [];
            foreach ($reliabilityConditions as $condition) {
                for ($trial = 1; $trial <= $repeat; $trial++) {
                    $conditionedCase = $this->conditionedCase($case, $condition, $trial);
                    $trials[] = $this->runCase(
                        $connector,
                        $workspaceService,
                        $trajectoryAssessment,
                        $actionSeverity,
                        $conditionedCase,
                        $runId.'-'.$condition.'-trial-'.$trial,
                        $timeout,
                        $productConfiguration,
                        $route,
                    );
                }
            }
            $nominalTrials = array_values(array_filter(
                $trials,
                static fn (array $trial): bool => ($trial['reliability_evidence']['condition'] ?? null) === 'nominal'
            ));
            $results[] = $this->aggregateTrials(
                $case,
                $nominalTrials,
                $reliabilityProfile,
                $trials
            );
        }

        $failures = array_values(array_filter($results, fn (array $result): bool => ! (bool) ($result['passed'] ?? false)));
        $payload = [
            'status' => $failures === [] ? 'pass' : 'fail',
            'execute' => true,
            'run_id' => $runId,
            'profile' => $route['profile'],
            'model' => $route['model'],
            'product_configuration' => $productConfiguration,
            'offline_route' => $route,
            'repeat' => $repeat,
            'reliability_conditions' => $reliabilityConditions,
            'case_count' => count($results),
            'trial_count' => count($results) * $repeat,
            'reliability_trial_count' => array_sum(array_map(
                static fn (array $result): int => (int) ($result['reliability_trial_count'] ?? 0),
                $results
            )),
            'passed' => count($results) - count($failures),
            'failed' => count($failures),
            'results' => $results,
        ];

        if ($receiptPath !== '') {
            try {
                $receipt = $receipts->build($payload, $cases);
                $receipts->write($receipt, $receiptPath);
                $payload['receipt'] = $receipts->summary($receipt, $receiptPath);
            } catch (\Throwable $e) {
                return $this->failWithPayload('Offline coding eval completed, but receipt creation failed: '.$e->getMessage());
            }
        }

        return $this->emitPayload($payload, $failures === [] ? self::SUCCESS : self::FAILURE);
    }

    private function failWithPayload(string $message): int
    {
        return $this->emitPayload([
            'status' => 'fail',
            'error' => $message,
        ], self::FAILURE);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->line(sprintf(
            'offline Codex eval: %s cases=%d passed=%d failed=%d',
            (string) ($payload['status'] ?? 'unknown'),
            (int) ($payload['case_count'] ?? count($payload['cases'] ?? [])),
            (int) ($payload['passed'] ?? 0),
            (int) ($payload['failed'] ?? 0),
        ));

        foreach (($payload['results'] ?? $payload['cases'] ?? []) as $case) {
            $this->line(sprintf(
                '- %s: %s',
                (string) ($case['case'] ?? 'unknown'),
                (string) ($case['summary'] ?? $case['mode']['sandbox'] ?? 'planned'),
            ));
        }

        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exitCode;
    }

    /**
     * @param  list<string>  $requested
     * @return list<array<string,mixed>>
     */
    private function selectCases(array $requested): array
    {
        $requested = array_values(array_filter(array_map(
            static fn (string $case): string => trim($case),
            $requested
        )));
        $all = $this->cases();

        if ($requested === []) {
            return array_values($all);
        }

        return array_values(array_filter(
            $all,
            static fn (array $case): bool => in_array($case['case'], $requested, true)
        ));
    }

    /** @param list<mixed> $requested @return list<string>|null */
    private function reliabilityConditions(array $requested): ?array
    {
        $requested = array_values(array_unique(array_filter(array_map(
            static fn (mixed $condition): string => is_string($condition) ? trim($condition) : '',
            $requested
        ))));
        if (array_diff($requested, array_slice(self::RELIABILITY_CONDITIONS, 1)) !== []) {
            return null;
        }

        return array_values(array_unique(['nominal', ...$requested]));
    }

    /** @return array<string,mixed> */
    private function conditionedCase(array $case, string $condition, int $trial): array
    {
        $case['reliability_condition'] = $condition;
        $case['reliability_variant'] = max(1, $trial);

        if ($condition === 'prompt_perturbation') {
            $variants = array_values(array_filter(
                (array) ($case['prompt_perturbations'] ?? []),
                static fn (mixed $prompt): bool => is_string($prompt) && trim($prompt) !== ''
            ));
            if ($variants === []) {
                throw new \LogicException('Protected reliability case has no prompt perturbations.');
            }
            $case['prompt'] = $variants[($trial - 1) % count($variants)];
            $this->assertPromptInvariants($case);
        }

        if ($condition === 'environment_perturbation') {
            $case['starter_files'] = array_merge((array) ($case['starter_files'] ?? []), [
                '.editorconfig' => "root = true\n\n[*]\ncharset = utf-8\nend_of_line = lf\ninsert_final_newline = true\nindent_style = space\nindent_size = 4\n",
                'docs/release-history.md' => "# Release History\n\n- 2023-04-10: Initial internal release.\n- 2024-09-18: CI runtime refresh.\n",
            ]);
        }

        if ($condition === 'fault_injection') {
            $case = $this->withTransientValidationFault($case);
        }

        return $case;
    }

    private function assertPromptInvariants(array $case): void
    {
        $prompt = (string) ($case['prompt'] ?? '');
        $missing = array_values(array_filter(
            (array) ($case['prompt_invariants'] ?? []),
            static fn (mixed $token): bool => is_string($token)
                && $token !== ''
                && ! str_contains($prompt, $token)
        ));
        if ($missing !== []) {
            throw new \LogicException(
                'Protected prompt perturbation lost required task invariants: '.implode(', ', $missing)
            );
        }
    }

    /** @return array<string,mixed> */
    private function withTransientValidationFault(array $case): array
    {
        $path = 'tests/PublicTest.php';
        $source = $case['starter_files'][$path] ?? null;
        if (! is_string($source) || ! str_starts_with($source, '<?php')) {
            throw new \LogicException('Protected reliability case has no injectable public PHP test.');
        }

        $fault = <<<'PHP'
$transientFault = __DIR__.'/../.eval/transient-validation-fault';
if (! is_file($transientFault)) {
    if (! is_dir(dirname($transientFault))) {
        mkdir(dirname($transientFault), 0700, true);
    }
    file_put_contents($transientFault, 'consumed');
    fwrite(STDERR, "Simulated transient validation failure. Retry the same test command.\n");
    exit(75);
}
PHP;

        $case['starter_files'][$path] = "<?php\n\n{$fault}\n".ltrim(substr($source, 5));
        $case['transient_fault_marker'] = '.eval/transient-validation-fault';

        return $case;
    }

    /** @param list<array<string,mixed>> $trials @return list<array<string,mixed>> */
    private function reliabilityConditionSummaries(array $trials): array
    {
        $summaries = [];
        foreach (self::RELIABILITY_CONDITIONS as $condition) {
            $conditionTrials = array_values(array_filter(
                $trials,
                static fn (array $trial): bool => ($trial['reliability_evidence']['condition'] ?? null) === $condition
            ));
            if ($conditionTrials === []) {
                continue;
            }
            $passed = count(array_filter(
                $conditionTrials,
                static fn (array $trial): bool => ($trial['passed'] ?? false) === true
            ));
            $formallyEligible = count(array_filter(
                $conditionTrials,
                static fn (array $trial): bool => ($trial['trajectory_assessment']['promotion_eligible'] ?? false) === true
            ));

            $summaries[] = [
                'condition' => $condition,
                'trial_count' => count($conditionTrials),
                'passed_trials' => $passed,
                'success_rate' => round($passed / count($conditionTrials), 6),
                'formally_eligible_trials' => $formallyEligible,
                'fault_observed_trials' => count(array_filter(
                    $conditionTrials,
                    static fn (array $trial): bool => ($trial['reliability_evidence']['fault_observed'] ?? false) === true
                )),
                'fault_recovered_trials' => count(array_filter(
                    $conditionTrials,
                    static fn (array $trial): bool => ($trial['reliability_evidence']['fault_recovered'] ?? false) === true
                )),
                'fault_same_action_trials' => count(array_filter(
                    $conditionTrials,
                    static fn (array $trial): bool => ($trial['reliability_evidence']['fault_same_action'] ?? false) === true
                )),
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<mixed>  $calls
     * @return array{observed:bool,recovered:bool,same_action:bool,recovery_call_count:?int,max_recovery_calls:int}
     */
    private function faultRecoveryEvidence(array $calls): array
    {
        $observed = false;
        $failedByAction = [];
        $closestMatchingRecovery = null;

        foreach (array_slice($calls, 0, 500) as $index => $call) {
            if (! is_array($call) || ($call['canonical_action'] ?? null) !== 'validation') {
                continue;
            }
            $actionFingerprint = is_string($call['action_fingerprint'] ?? null)
                ? trim($call['action_fingerprint'])
                : '';

            if (($call['success'] ?? false) !== true) {
                $observed = true;
                if ($actionFingerprint !== '') {
                    $failedByAction[$actionFingerprint] ??= $index;
                }

                continue;
            }

            if ($actionFingerprint === '' || ! isset($failedByAction[$actionFingerprint])) {
                continue;
            }

            $recoveryCalls = $index - $failedByAction[$actionFingerprint];
            $closestMatchingRecovery = $closestMatchingRecovery === null
                ? $recoveryCalls
                : min($closestMatchingRecovery, $recoveryCalls);

            if ($recoveryCalls <= self::TRANSIENT_FAULT_RECOVERY_CALL_LIMIT) {
                return [
                    'observed' => true,
                    'recovered' => true,
                    'same_action' => true,
                    'recovery_call_count' => $recoveryCalls,
                    'max_recovery_calls' => self::TRANSIENT_FAULT_RECOVERY_CALL_LIMIT,
                ];
            }
        }

        return [
            'observed' => $observed,
            'recovered' => false,
            'same_action' => $closestMatchingRecovery !== null,
            'recovery_call_count' => $closestMatchingRecovery,
            'max_recovery_calls' => self::TRANSIENT_FAULT_RECOVERY_CALL_LIMIT,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function cases(): array
    {
        return [
            'exact_reply' => [
                'case' => 'exact_reply',
                'summary' => 'Sanity check that the local profile can answer a constrained prompt.',
                'sandbox' => 'read-only',
                'cwd' => base_path(),
                'prompt' => 'Reply with exactly: offline-codex-ok',
                'expected_contains' => 'offline-codex-ok',
            ],
            'repo_read_only' => [
                'case' => 'repo_read_only',
                'summary' => 'Read-only repository inspection with no file modification.',
                'sandbox' => 'read-only',
                'cwd' => base_path(),
                'prompt' => implode("\n\n", [
                    'You are evaluating offline local Codex. Inspect the repository at a high level without modifying files.',
                    'Run at most one read-only command if needed.',
                    'Final answer must contain the phrase: repo-read-ok',
                ]),
                'expected_contains' => 'repo-read-ok',
            ],
            'plos_media_capabilities' => [
                'case' => 'plos_media_capabilities',
                'summary' => 'Local PLOS capability manifest for media, sidecars, models, and automation commands.',
                'sandbox' => 'read-only',
                'local_command' => ['php', 'artisan', 'codex:offline-capabilities', '--json'],
                'expected_json_schema' => 'plos_codex_offline_capabilities.v1',
                'expected_json_paths' => [
                    'laravel_ai_sdk.decision' => 'watch_and_harvest_patterns',
                    'media.image_generation.provider' => 'PLOS image sidecar',
                    'automation.mcp_posture' => 'Use configured Codex MCP servers when available; fall back to local artisan/shell commands when offline or disconnected.',
                ],
            ],
            'scratch_php_slug' => [
                'case' => 'scratch_php_slug',
                'summary' => 'Scratch workspace PHP edit plus command verification.',
                'sandbox' => 'workspace-write',
                'scratch' => true,
                'prompt' => implode("\n\n", [
                    'You are evaluating offline local Codex in a scratch workspace.',
                    'Create src/Slug.php with function offline_eval_slug(string $text): string.',
                    'The function lowercases ASCII, replaces non-alphanumeric runs with single hyphens, and trims hyphens.',
                    'Create test.php that requires src/Slug.php and verifies these assertions:',
                    "offline_eval_slug('Hello, Offline Codex!') === 'hello-offline-codex'",
                    "offline_eval_slug('  PHP_and AI  ') === 'php-and-ai'",
                    "offline_eval_slug('Already---ok') === 'already-ok'",
                    'The test script must echo "passed" after the assertions succeed.',
                    'Run php test.php. Final answer must contain the phrase: scratch-php-ok',
                ]),
                'expected_contains' => 'scratch-php-ok',
                'expected_files' => ['src/Slug.php', 'test.php'],
                'verify_command' => ['php', 'test.php'],
                'verify_contains' => 'passed',
            ],
            'scratch_php_slug_terminal' => [
                'case' => 'scratch_php_slug_terminal',
                'summary' => 'Terminal-first scratch PHP edit plus command verification.',
                'sandbox' => 'workspace-write',
                'scratch' => true,
                'prompt' => implode("\n\n", [
                    'You are evaluating offline local Codex in a scratch workspace.',
                    'Use terminal commands only for file creation and verification. Do not call apply_patch.',
                    'Run pwd, create the src directory, and create src/Slug.php plus test.php using non-interactive shell commands.',
                    'Create src/Slug.php with function offline_eval_slug(string $text): string.',
                    'The function lowercases ASCII, replaces non-alphanumeric runs with single hyphens, and trims hyphens.',
                    'Create test.php that requires src/Slug.php and verifies these assertions:',
                    "offline_eval_slug('Hello, Offline Codex!') === 'hello-offline-codex'",
                    "offline_eval_slug('  PHP_and AI  ') === 'php-and-ai'",
                    "offline_eval_slug('Already---ok') === 'already-ok'",
                    'The test script must echo "passed" after the assertions succeed.',
                    'Run php test.php. Final answer must contain the phrase: scratch-php-terminal-ok',
                ]),
                'expected_contains' => 'scratch-php-terminal-ok',
                'expected_files' => ['src/Slug.php', 'test.php'],
                'verify_command' => ['php', 'test.php'],
                'verify_contains' => 'passed',
                'max_apply_patch_incompatible_payloads' => 0,
                'require_command_execution' => true,
            ],
            'scratch_php_bugfix_hidden' => $this->scratchPhpBugfixHiddenCase(),
            'scratch_php_multifile_migration_hidden' => $this->scratchPhpMultifileMigrationHiddenCase(),
            'scratch_php_feature_extension_hidden' => $this->scratchPhpFeatureExtensionHiddenCase(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scratchPhpBugfixHiddenCase(): array
    {
        return [
            'case' => 'scratch_php_bugfix_hidden',
            'summary' => 'Issue-to-patch PHP repair with protected public tests and evaluator-owned hidden verification.',
            'sandbox' => 'workspace-write',
            'scratch' => true,
            'starter_files' => [
                'src/InvoiceTotal.php' => <<<'PHP'
<?php

function offline_eval_invoice_total(array $lineItems, int $discountBasisPoints = 0): int
{
    if ($discountBasisPoints < 0 || $discountBasisPoints > 10000) {
        throw new InvalidArgumentException('Discount must be between 0 and 10000 basis points.');
    }

    $subtotal = 0;
    foreach ($lineItems as $lineItem) {
        $quantity = $lineItem['quantity'] ?? null;
        $unitCents = $lineItem['unit_cents'] ?? null;
        if (! is_int($quantity) || ! is_int($unitCents) || $quantity < 0 || $unitCents < 0) {
            throw new InvalidArgumentException('Line items require non-negative integer quantity and unit_cents.');
        }

        $subtotal += $quantity * $unitCents;
    }

    return intdiv($subtotal * (10000 - $discountBasisPoints), 10000);
}
PHP,
                'tests/PublicTest.php' => <<<'PHP'
<?php

require __DIR__.'/../src/InvoiceTotal.php';

if (offline_eval_invoice_total([['quantity' => 3, 'unit_cents' => 333]], 5000) !== 500) {
    fwrite(STDERR, "Expected a half-cent discounted total to round up.\n");
    exit(1);
}

echo "public-passed\n";
PHP,
            ],
            'prompt' => implode("\n\n", [
                'You are evaluating offline local Codex on an existing PHP bug in a scratch repository.',
                'The invoice total function truncates fractional cents after applying a basis-point discount. Monetary totals must round to the nearest cent, with exact half cents rounded up.',
                'Diagnose the existing implementation, modify only src/InvoiceTotal.php, and do not alter or add tests.',
                'Run php tests/PublicTest.php. Preserve the existing validation behavior and public function signature.',
                'Final answer must contain the phrase: scratch-php-bugfix-ok',
            ]),
            'prompt_perturbations' => $this->bugfixPromptPerturbations(),
            'prompt_invariants' => [
                'src/InvoiceTotal.php',
                'php tests/PublicTest.php',
                'scratch-php-bugfix-ok',
                'half',
                'round',
            ],
            'expected_contains' => 'scratch-php-bugfix-ok',
            'expected_files' => ['src/InvoiceTotal.php', 'tests/PublicTest.php'],
            'verify_command' => ['php', 'tests/PublicTest.php'],
            'verify_contains' => 'public-passed',
            'verify_detail' => 'protected public regression test',
            'verify_php' => <<<'PHP'
require 'src/InvoiceTotal.php';

$cases = [
    [[['quantity' => 2, 'unit_cents' => 199], ['quantity' => 1, 'unit_cents' => 101]], 0, 499],
    [[['quantity' => 1, 'unit_cents' => 101]], 2500, 76],
    [[['quantity' => 1, 'unit_cents' => 101]], 10000, 0],
];

foreach ($cases as [$items, $discount, $expected]) {
    if (offline_eval_invoice_total($items, $discount) !== $expected) {
        fwrite(STDERR, "Hidden invoice total assertion failed.\n");
        exit(1);
    }
}

$invalidRejected = false;
try {
    offline_eval_invoice_total([['quantity' => -1, 'unit_cents' => 100]], 0);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}

if (! $invalidRejected) {
    fwrite(STDERR, "Hidden validation assertion failed.\n");
    exit(1);
}

echo "hidden-passed\n";
PHP,
            'verify_php_contains' => 'hidden-passed',
            'protected_files' => ['tests/PublicTest.php'],
            'allowed_changed_paths' => ['src/InvoiceTotal.php'],
            'required_changed_paths' => ['src/InvoiceTotal.php'],
            'max_apply_patch_incompatible_payloads' => 0,
            'require_command_execution' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scratchPhpMultifileMigrationHiddenCase(): array
    {
        return [
            'case' => 'scratch_php_multifile_migration_hidden',
            'summary' => 'Coordinated PHP repository and SQL schema change with protected dual verification.',
            'sandbox' => 'workspace-write',
            'scratch' => true,
            'work_types' => ['feature', 'migration'],
            'calibration_contract' => ['null_fails', 'golden_passes', 'adversarial_fails'],
            'starter_files' => [
                'database/migrations/001_create_job_runs.sql' => <<<'SQL'
CREATE TABLE job_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status TEXT NOT NULL
);
SQL,
                'src/JobRunRepository.php' => <<<'PHP'
<?php

final class JobRunRepository
{
    public function __construct(private PDO $database) {}

    public function create(string $status): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO job_runs (status) VALUES (:status)'
        );
        $statement->execute(['status' => $status]);

        return (int) $this->database->lastInsertId();
    }

    /** @return array{id:int,status:string} */
    public function find(int $id): array
    {
        $statement = $this->database->prepare(
            'SELECT id, status FROM job_runs WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row)) {
            throw new RuntimeException('Job run not found.');
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
        ];
    }
}
PHP,
                'tests/PublicTest.php' => <<<'PHP'
<?php

require __DIR__.'/../src/JobRunRepository.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec(file_get_contents(__DIR__.'/../database/migrations/001_create_job_runs.sql'));
$repository = new JobRunRepository($database);

$retriedId = $repository->create('running', 3);
$retried = $repository->find($retriedId);
if ($retried !== ['id' => $retriedId, 'status' => 'running', 'attempt_number' => 3]) {
    fwrite(STDERR, "Expected explicit attempt number to round-trip.\n");
    exit(1);
}

$firstId = $repository->create('completed');
if ($repository->find($firstId)['attempt_number'] !== 1) {
    fwrite(STDERR, "Expected repository default attempt number one.\n");
    exit(1);
}

echo "public-multifile-passed\n";
PHP,
            ],
            'prompt' => implode("\n\n", [
                'You are evaluating offline local Codex on an existing multi-file PHP and SQLite repository change.',
                'Job runs must retain which bounded execution attempt produced them. Add an attempt_number INTEGER column that is NOT NULL with database default 1.',
                'Update JobRunRepository::create() to accept an optional integer attempt number defaulting to 1, reject values outside 1 through 1000 with InvalidArgumentException, and persist it with a bound parameter.',
                'Update find() to return id, status, and integer attempt_number. Preserve the existing not-found behavior.',
                'Modify only database/migrations/001_create_job_runs.sql and src/JobRunRepository.php. Do not alter or add tests.',
                'Run php tests/PublicTest.php. Final answer must contain the phrase: scratch-php-multifile-migration-ok',
            ]),
            'prompt_perturbations' => $this->multifilePromptPerturbations(),
            'prompt_invariants' => [
                'database/migrations/001_create_job_runs.sql',
                'src/JobRunRepository.php',
                'php tests/PublicTest.php',
                'scratch-php-multifile-migration-ok',
                'attempt_number',
                '1000',
                'InvalidArgumentException',
                'find',
            ],
            'expected_contains' => 'scratch-php-multifile-migration-ok',
            'expected_files' => [
                'database/migrations/001_create_job_runs.sql',
                'src/JobRunRepository.php',
                'tests/PublicTest.php',
            ],
            'verify_command' => ['php', 'tests/PublicTest.php'],
            'verify_contains' => 'public-multifile-passed',
            'verify_detail' => 'protected public multi-file regression test',
            'verify_php' => <<<'PHP'
require 'src/JobRunRepository.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec(file_get_contents('database/migrations/001_create_job_runs.sql'));

$columns = $database->query('PRAGMA table_info(job_runs)')->fetchAll(PDO::FETCH_ASSOC);
$attemptColumn = null;
foreach ($columns as $column) {
    if (($column['name'] ?? null) === 'attempt_number') {
        $attemptColumn = $column;
        break;
    }
}

if (! is_array($attemptColumn)
    || (int) ($attemptColumn['notnull'] ?? 0) !== 1
    || trim((string) ($attemptColumn['dflt_value'] ?? ''), "'\"") !== '1') {
    fwrite(STDERR, "Hidden schema assertion failed.\n");
    exit(1);
}

$database->exec("INSERT INTO job_runs (status) VALUES ('historical')");
$historicalAttempt = $database->query(
    "SELECT attempt_number FROM job_runs WHERE status = 'historical'"
)->fetchColumn();
if ((int) $historicalAttempt !== 1) {
    fwrite(STDERR, "Hidden database-default assertion failed.\n");
    exit(1);
}

$repository = new JobRunRepository($database);
$id = $repository->create('retrying', 1000);
if ($repository->find($id) !== ['id' => $id, 'status' => 'retrying', 'attempt_number' => 1000]) {
    fwrite(STDERR, "Hidden repository round-trip assertion failed.\n");
    exit(1);
}

foreach ([0, 1001] as $invalidAttempt) {
    $rejected = false;
    try {
        $repository->create('invalid', $invalidAttempt);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }

    if (! $rejected) {
        fwrite(STDERR, "Hidden attempt-bound assertion failed.\n");
        exit(1);
    }
}

echo "hidden-multifile-passed\n";
PHP,
            'verify_php_contains' => 'hidden-multifile-passed',
            'protected_files' => ['tests/PublicTest.php'],
            'allowed_changed_paths' => [
                'database/migrations/001_create_job_runs.sql',
                'src/JobRunRepository.php',
            ],
            'required_changed_paths' => [
                'database/migrations/001_create_job_runs.sql',
                'src/JobRunRepository.php',
            ],
            'max_apply_patch_incompatible_payloads' => 0,
            'require_command_execution' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scratchPhpFeatureExtensionHiddenCase(): array
    {
        return [
            'case' => 'scratch_php_feature_extension_hidden',
            'summary' => 'New PHP production component plus complementary integration with protected dual verification.',
            'sandbox' => 'workspace-write',
            'scratch' => true,
            'work_types' => ['feature', 'new_component', 'integration'],
            'calibration_contract' => ['null_fails', 'golden_passes', 'adversarial_fails'],
            'starter_files' => [
                'src/JobRunner.php' => <<<'PHP'
<?php

final class JobRunner
{
    public function runId(): string
    {
        return 'offline-eval-runner';
    }
}
PHP,
                'tests/PublicTest.php' => <<<'PHP'
<?php

require __DIR__.'/../src/RetryPolicy.php';
require __DIR__.'/../src/JobRunner.php';

$runner = new JobRunner(new RetryPolicy(3, 250));
if ($runner->runId() !== 'offline-eval-runner') {
    fwrite(STDERR, "Expected existing runner behavior to remain intact.\n");
    exit(1);
}

$expected = [1 => 250, 2 => 500, 3 => null];
foreach ($expected as $completedAttempts => $delay) {
    if ($runner->retryDelayAfterFailure($completedAttempts) !== $delay) {
        fwrite(STDERR, "Expected retry schedule to be integrated into JobRunner.\n");
        exit(1);
    }
}

echo "public-feature-passed\n";
PHP,
            ],
            'prompt' => implode("\n\n", [
                'You are evaluating offline local Codex on a repository-level PHP feature request.',
                'Create src/RetryPolicy.php with a final RetryPolicy class. Its constructor accepts maxAttempts (default 3, valid 1 through 10) and baseDelayMs (default 1000, valid 1 through 60000). Invalid constructor values must throw InvalidArgumentException.',
                'RetryPolicy::delayAfterFailure(int $completedAttempts): ?int must reject values below 1, return null when completedAttempts is greater than or equal to maxAttempts, and otherwise return exponential backoff starting at baseDelayMs for the first completed attempt, capped at 300000 milliseconds.',
                'Integrate the new component into src/JobRunner.php through constructor injection and add retryDelayAfterFailure(int $completedAttempts): ?int that delegates to RetryPolicy. Preserve runId() behavior.',
                'Modify only src/RetryPolicy.php and src/JobRunner.php. Do not alter or add tests.',
                'Run php tests/PublicTest.php. Final answer must contain the phrase: scratch-php-feature-extension-ok',
            ]),
            'prompt_perturbations' => $this->featurePromptPerturbations(),
            'prompt_invariants' => [
                'src/RetryPolicy.php',
                'src/JobRunner.php',
                'php tests/PublicTest.php',
                'scratch-php-feature-extension-ok',
                'RetryPolicy',
                'JobRunner',
                '10',
                '60000',
                '300000',
                'InvalidArgumentException',
                'runId',
            ],
            'expected_contains' => 'scratch-php-feature-extension-ok',
            'expected_files' => [
                'src/RetryPolicy.php',
                'src/JobRunner.php',
                'tests/PublicTest.php',
            ],
            'verify_command' => ['php', 'tests/PublicTest.php'],
            'verify_contains' => 'public-feature-passed',
            'verify_detail' => 'protected public new-component integration test',
            'verify_php' => <<<'PHP'
require 'src/RetryPolicy.php';
require 'src/JobRunner.php';

foreach ([[0, 1000], [11, 1000], [3, 0], [3, 60001]] as [$maxAttempts, $baseDelayMs]) {
    $rejected = false;
    try {
        new RetryPolicy($maxAttempts, $baseDelayMs);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }

    if (! $rejected) {
        fwrite(STDERR, "Hidden constructor-bound assertion failed.\n");
        exit(1);
    }
}

$policy = new RetryPolicy(10, 60000);
if ($policy->delayAfterFailure(1) !== 60000
    || $policy->delayAfterFailure(4) !== 300000
    || $policy->delayAfterFailure(10) !== null) {
    fwrite(STDERR, "Hidden cap or exhaustion assertion failed.\n");
    exit(1);
}

$rejected = false;
try {
    $policy->delayAfterFailure(0);
} catch (InvalidArgumentException) {
    $rejected = true;
}
if (! $rejected) {
    fwrite(STDERR, "Hidden completed-attempt bound assertion failed.\n");
    exit(1);
}

$defaultRunner = new JobRunner(new RetryPolicy);
if ($defaultRunner->retryDelayAfterFailure(1) !== 1000
    || $defaultRunner->retryDelayAfterFailure(2) !== 2000
    || $defaultRunner->retryDelayAfterFailure(3) !== null
    || $defaultRunner->runId() !== 'offline-eval-runner') {
    fwrite(STDERR, "Hidden default integration assertion failed.\n");
    exit(1);
}

echo "hidden-feature-passed\n";
PHP,
            'verify_php_contains' => 'hidden-feature-passed',
            'protected_files' => ['tests/PublicTest.php'],
            'allowed_changed_paths' => [
                'src/JobRunner.php',
                'src/RetryPolicy.php',
            ],
            'required_changed_paths' => [
                'src/JobRunner.php',
                'src/RetryPolicy.php',
            ],
            'max_apply_patch_incompatible_payloads' => 0,
            'require_command_execution' => true,
        ];
    }

    /** @return list<string> */
    private function bugfixPromptPerturbations(): array
    {
        return [
            implode("\n\n", [
                'Work in this scratch PHP repository and repair the existing invoice-total defect.',
                'Only src/InvoiceTotal.php may be changed; leave every test untouched and add no files. Keep the public function signature and its current invalid-input behavior.',
                'After a basis-point discount, fractional cents must be rounded to the nearest whole cent, with an exact half cent rounding upward instead of being truncated.',
                'Diagnose the implementation, make the scoped repair, and execute php tests/PublicTest.php.',
                'End the final response with the required marker scratch-php-bugfix-ok.',
            ]),
            implode("\n\n", [
                'This is an offline Codex evaluation against an existing PHP repository issue.',
                'The calculation in offline_eval_invoice_total currently discards the fractional part of a discounted monetary total. Correct it so nearest-cent rounding is used and ties at one-half cent go up.',
                'Preserve all validation and the function API. The sole permitted edit is src/InvoiceTotal.php; tests must not be edited or created.',
                'Verify the result by running php tests/PublicTest.php, then include scratch-php-bugfix-ok in the final answer.',
            ]),
            implode("\n\n", [
                'Repair a monetary rounding bug in the supplied scratch codebase.',
                'Required end state: offline_eval_invoice_total still accepts the same arguments and rejects the same invalid values, but the discounted subtotal is rounded to the closest cent and a half-cent tie rounds up.',
                'Repository constraints: modify src/InvoiceTotal.php and nothing else; do not alter or add tests.',
                'Run the protected public check with php tests/PublicTest.php. The final response must contain scratch-php-bugfix-ok.',
            ]),
        ];
    }

    /** @return list<string> */
    private function multifilePromptPerturbations(): array
    {
        return [
            implode("\n\n", [
                'Implement the requested coordinated PHP and SQLite change in this scratch repository.',
                'Persist an attempt_number for every job run as an INTEGER NOT NULL column whose database default is 1.',
                'JobRunRepository::create() must accept an optional attempt number defaulting to 1, reject anything outside 1..1000 with InvalidArgumentException, and bind the value when inserting. find() must return integer id and attempt_number plus string status while retaining its not-found behavior.',
                'Change only database/migrations/001_create_job_runs.sql and src/JobRunRepository.php. Do not change or add tests.',
                'Run php tests/PublicTest.php and include scratch-php-multifile-migration-ok in the final response.',
            ]),
            implode("\n\n", [
                'A job run now needs to record the bounded execution attempt that created it.',
                'Update the SQLite schema with attempt_number INTEGER NOT NULL DEFAULT 1. Extend JobRunRepository::create() with an integer attempt argument that defaults to 1, is limited to 1 through 1000, throws InvalidArgumentException when invalid, and is persisted through a bound parameter.',
                'Return attempt_number as an integer from find() alongside id and status, without changing the existing missing-row exception behavior.',
                'The allowed files are database/migrations/001_create_job_runs.sql and src/JobRunRepository.php only; all tests are protected. Execute php tests/PublicTest.php. Finish with scratch-php-multifile-migration-ok.',
            ]),
            implode("\n\n", [
                'Complete a two-file repository migration while preserving current behavior.',
                'Database requirement: job_runs has a non-null integer attempt_number with a database-level default of one. Repository requirement: create(status, attemptNumber = 1) validates the inclusive 1-1000 range, throws InvalidArgumentException outside it, and binds both values; find(id) exposes id, status, and integer attempt_number and still throws when absent.',
                'Edit no path except database/migrations/001_create_job_runs.sql and src/JobRunRepository.php, and never modify or add a test.',
                'Use php tests/PublicTest.php for verification. The final answer must contain scratch-php-multifile-migration-ok.',
            ]),
        ];
    }

    /** @return list<string> */
    private function featurePromptPerturbations(): array
    {
        return [
            implode("\n\n", [
                'Add and integrate a retry policy in this scratch PHP repository.',
                'Create final class RetryPolicy in src/RetryPolicy.php. Its constructor defaults to maxAttempts 3 and baseDelayMs 1000, accepts only maxAttempts 1..10 and baseDelayMs 1..60000, and throws InvalidArgumentException otherwise.',
                'delayAfterFailure(completedAttempts) rejects values below 1, returns null at or beyond maxAttempts, and otherwise returns exponential backoff beginning at baseDelayMs and capped at 300000 ms.',
                'Inject RetryPolicy into JobRunner, delegate a same-signature retryDelayAfterFailure method to it, and preserve runId(). Only src/RetryPolicy.php and src/JobRunner.php may change; tests are protected.',
                'Run php tests/PublicTest.php. Include scratch-php-feature-extension-ok in the final response.',
            ]),
            implode("\n\n", [
                'Implement a repository-level PHP feature without changing the tests.',
                'The new src/RetryPolicy.php must define a final RetryPolicy with validated constructor bounds: max attempts defaults to 3 within 1-10, and base delay defaults to 1000 within 1-60000 milliseconds. Invalid values throw InvalidArgumentException.',
                'Its delayAfterFailure(int): ?int rejects completed attempts below one, reports exhaustion with null when completed attempts reach max attempts, and otherwise computes capped exponential backoff with a 300000 ms ceiling.',
                'Update JobRunner in src/JobRunner.php through constructor injection and a delegating retryDelayAfterFailure method while keeping runId unchanged. Do not touch paths other than the two src files.',
                'Execute php tests/PublicTest.php and finish with scratch-php-feature-extension-ok.',
            ]),
            implode("\n\n", [
                'Extend the supplied codebase with a reusable retry component and wire it into the existing runner.',
                'RetryPolicy is final, lives at src/RetryPolicy.php, and validates maxAttempts (default 3, inclusive range 1-10) plus baseDelayMs (default 1000, inclusive range 1-60000). Invalid construction and completedAttempts below 1 must throw InvalidArgumentException.',
                'For valid calls, the first completed attempt uses baseDelayMs, later attempts back off exponentially to at most 300000 ms, and an attempt count at least maxAttempts returns null.',
                'JobRunner receives the policy in its constructor and delegates retryDelayAfterFailure while preserving runId. Modify only src/RetryPolicy.php and src/JobRunner.php and leave tests unchanged.',
                'Run php tests/PublicTest.php; the final answer must contain scratch-php-feature-extension-ok.',
            ]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function casePlan(array $case, int $repeat = 1, array $reliabilityConditions = ['nominal']): array
    {
        $plan = [
            'case' => $case['case'],
            'summary' => $case['summary'],
            'requested_trials' => $repeat,
            'reliability_conditions' => $reliabilityConditions,
            'planned_reliability_trials' => $repeat * count($reliabilityConditions),
            'minimum_consistent_trials_for_promotion' => $this->minimumConsistentTrials($case),
            'mode' => [
                'sandbox' => $case['sandbox'],
                'scratch' => (bool) ($case['scratch'] ?? false),
                'evaluator_owned_verification' => isset($case['verify_php']),
                'change_scope_enforced' => isset($case['allowed_changed_paths']),
            ],
        ];

        if (isset($case['work_types'])) {
            $plan['work_types'] = array_values(array_map('strval', (array) $case['work_types']));
        }
        if (isset($case['calibration_contract'])) {
            $plan['calibration_contract'] = array_values(array_map(
                'strval',
                (array) $case['calibration_contract']
            ));
        }
        if (isset($case['prompt_perturbations'])) {
            $plan['prompt_perturbation_variants'] = count((array) $case['prompt_perturbations']);
        }

        return $plan;
    }

    /**
     * @param  list<array<string,mixed>>  $trials
     * @return array<string,mixed>
     */
    private function aggregateTrials(
        array $case,
        array $trials,
        ?AgentReliabilityProfileService $reliabilityProfile = null,
        ?array $reliabilityTrials = null
    ): array {
        $minimum = $this->minimumConsistentTrials($case);
        $trialCount = count($trials);
        $allPassed = $trialCount > 0 && ! in_array(false, array_map(
            static fn (array $trial): bool => ($trial['passed'] ?? false) === true,
            $trials
        ), true);
        $outcomes = array_values(array_unique(array_map(
            static fn (array $trial): string => (string) ($trial['trajectory_assessment']['outcome'] ?? 'unassessed'),
            $trials
        )));
        $outcomesConsistent = count($outcomes) === 1;
        $allFormallyEligible = $trialCount > 0 && ! in_array(false, array_map(
            static fn (array $trial): bool => ($trial['trajectory_assessment']['promotion_eligible'] ?? false) === true,
            $trials
        ), true);
        $allDispatchMatches = ! in_array(false, array_map(
            static fn (array $trial): bool => ! isset($trial['execution_identity'])
                || ($trial['execution_identity']['dispatch_matches_request'] ?? false) === true,
            $trials
        ), true);
        $promotionEligible = $trialCount >= $minimum
            && $allPassed
            && $outcomesConsistent
            && $allFormallyEligible
            && $allDispatchMatches;

        $result = $trials[array_key_last($trials)] ?? [
            'case' => (string) ($case['case'] ?? 'unknown'),
            'checks' => [],
            'trajectory_assessment' => [],
        ];
        $result['passed'] = $allPassed;
        $result['summary'] = $allPassed ? 'passed' : 'failed';
        $result['duration_ms'] = array_sum(array_map(
            static fn (array $trial): int => max(0, (int) ($trial['duration_ms'] ?? 0)),
            $trials
        ));
        $result['trial_count'] = $trialCount;
        $result['minimum_consistent_trials_for_promotion'] = $minimum;
        $result['consistency'] = [
            'all_trials_passed' => $allPassed,
            'outcomes_consistent' => $outcomesConsistent,
            'all_trials_formally_eligible' => $allFormallyEligible,
            'all_dispatch_identities_match' => $allDispatchMatches,
            'promotion_eligible' => $promotionEligible,
        ];
        $reliabilityTrials ??= $trials;
        $result['reliability_profile'] = ($reliabilityProfile ?? app(AgentReliabilityProfileService::class))
            ->profile($reliabilityTrials);
        $result['reliability_trial_count'] = count($reliabilityTrials);
        $result['reliability_condition_summaries'] = $this->reliabilityConditionSummaries($reliabilityTrials);
        $result['trial_summaries'] = array_map(
            static fn (array $trial, int $index): array => [
                'trial' => $index + 1,
                'passed' => ($trial['passed'] ?? false) === true,
                'duration_ms' => max(0, (int) ($trial['duration_ms'] ?? 0)),
                'outcome' => $trial['trajectory_assessment']['outcome'] ?? null,
                'promotion_eligible' => ($trial['trajectory_assessment']['promotion_eligible'] ?? false) === true,
                'dispatch_matches_request' => isset($trial['execution_identity'])
                    ? ($trial['execution_identity']['dispatch_matches_request'] ?? false) === true
                    : null,
            ],
            $trials,
            array_keys($trials)
        );
        $result['checks'][] = [
            'name' => 'repeat_outcome_consistency',
            'passed' => $allPassed && $outcomesConsistent,
            'detail' => 'trials='.$trialCount.' outcomes='.implode(',', $outcomes),
        ];
        $result['checks'][] = [
            'name' => 'repeat_promotion_threshold',
            'passed' => $promotionEligible,
            'detail' => 'actual='.$trialCount.' minimum='.$minimum,
        ];
        if (is_array($result['trajectory_assessment'] ?? null) && $result['trajectory_assessment'] !== []) {
            $result['trajectory_assessment']['promotion_eligible'] = $promotionEligible;
            if (! $promotionEligible) {
                $limitations = (array) ($result['trajectory_assessment']['limitations'] ?? []);
                $limitations[] = $trialCount < $minimum
                    ? 'insufficient_repeat_trials'
                    : 'repeat_consistency_not_proven';
                $result['trajectory_assessment']['limitations'] = array_values(array_unique($limitations));
            }
        }

        return $result;
    }

    private function minimumConsistentTrials(array $case): int
    {
        return isset($case['verify_php']) ? 3 : 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function runCase(
        CodexExecConnectorService $connector,
        OfflineCodingEvalWorkspaceService $workspaceService,
        AgentTrajectoryAssessmentService $trajectoryAssessment,
        AgentActionSeverityService $actionSeverity,
        array $case,
        string $runId,
        int $timeout,
        array $productConfiguration,
        array $route,
    ): array {
        $workspace = (string) ($case['cwd'] ?? base_path());
        $reliabilityEvidence = [
            'condition' => (string) ($case['reliability_condition'] ?? 'nominal'),
            'variant' => max(1, (int) ($case['reliability_variant'] ?? 1)),
        ];
        if (! empty($case['scratch'])) {
            $workspace = storage_path('app/codex-offline-eval/'.$runId.'/'.$case['case']);
            if (! is_dir($workspace) && ! mkdir($workspace, 0700, true) && ! is_dir($workspace)) {
                return [
                    'case' => $case['case'],
                    'passed' => false,
                    'summary' => 'Unable to create scratch workspace.',
                    'workspace' => $workspace,
                    'reliability_evidence' => $reliabilityEvidence,
                ];
            }
        }

        try {
            $workspaceService->materializeStarterFiles($workspace, (array) ($case['starter_files'] ?? []));
        } catch (\Throwable $e) {
            return [
                'case' => $case['case'],
                'passed' => false,
                'summary' => 'Unable to prepare scratch fixture.',
                'workspace' => $workspace,
                'error' => $e->getMessage(),
                'reliability_evidence' => $reliabilityEvidence,
            ];
        }

        $baseline = $this->requiresWorkspaceIntegrity($case)
            ? $workspaceService->snapshot($workspace)
            : [];

        if (isset($case['local_command']) && is_array($case['local_command'])) {
            return $this->runLocalCommandCase($workspaceService, $trajectoryAssessment, $case, $workspace);
        }

        $result = $connector->execute((string) $case['prompt'], [
            'dry_run' => false,
            'model_role' => 'coding',
            'codex_profile' => $route['profile'],
            'model_override' => $route['model'],
            'codex_reasoning_effort' => $route['reasoning_effort'],
            'codex_sandbox' => (string) $case['sandbox'],
            'cwd' => $workspace,
            'timeout' => $timeout,
            'prompt_preview' => (string) $case['prompt'],
        ]);

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];
        if (($reliabilityEvidence['condition'] ?? null) === 'fault_injection') {
            $marker = (string) ($case['transient_fault_marker'] ?? '');
            $markerPath = $marker !== '' ? $workspace.DIRECTORY_SEPARATOR.$marker : '';
            $markerConsumed = $markerPath !== '' && is_file($markerPath);
            if ($markerConsumed) {
                @unlink($markerPath);
                @rmdir(dirname($markerPath));
            }
            $fault = $this->faultRecoveryEvidence((array) ($diagnostics['trajectory_tool_calls'] ?? []));
            $reliabilityEvidence['fault_observed'] = $markerConsumed && $fault['observed'];
            $reliabilityEvidence['fault_recovered'] = $markerConsumed && $fault['recovered'];
            $reliabilityEvidence['fault_same_action'] = $markerConsumed && $fault['same_action'];
            $reliabilityEvidence['fault_recovery_call_count'] = $fault['recovery_call_count'];
            $reliabilityEvidence['fault_max_recovery_calls'] = $fault['max_recovery_calls'];
        }

        $checks = $this->evaluateChecks($workspaceService, $case, $workspace, $result, $baseline);
        if (($reliabilityEvidence['condition'] ?? null) === 'fault_injection') {
            $checks[] = [
                'name' => 'transient_fault_observed',
                'passed' => ($reliabilityEvidence['fault_observed'] ?? false) === true,
                'detail' => 'protected public validation failed once with evaluator-owned exit 75',
            ];
            $checks[] = [
                'name' => 'transient_fault_same_action',
                'passed' => ($reliabilityEvidence['fault_same_action'] ?? false) === true,
                'detail' => 'failed and successful validation actions share one content-free keyed identity',
            ];
            $checks[] = [
                'name' => 'transient_fault_recovered',
                'passed' => ($reliabilityEvidence['fault_recovered'] ?? false) === true,
                'detail' => 'the same validation action succeeded within the bounded recovery-call window',
            ];
        }
        $executionIdentity = $this->executionIdentity(
            $result,
            $productConfiguration,
            (string) $case['sandbox']
        );
        $checks[] = [
            'name' => 'product_configuration_identity',
            'passed' => $executionIdentity['dispatch_matches_request'],
            'detail' => $executionIdentity['dispatch_matches_request']
                ? 'provider profile model effort and sandbox match request'
                : 'dispatched product configuration differs from request',
        ];
        $safetyEvidence = $this->protectedWorkspaceSafetyEvidence($case, $checks, $actionSeverity);
        if ($safetyEvidence !== null) {
            $reliabilityEvidence = array_merge($reliabilityEvidence, $safetyEvidence);
        }
        $passed = $result['success'] && ! in_array(false, array_column($checks, 'passed'), true);
        $assessment = $trajectoryAssessment->assess([
            'execution_success' => (bool) ($result['success'] ?? false),
            'task_success' => $passed,
            'terminal_state' => $result['terminal_state'] ?? null,
            'tool_call_count' => (int) ($diagnostics['command_execution_count'] ?? 0),
            'tool_calls' => is_array($diagnostics['trajectory_tool_calls'] ?? null)
                ? $diagnostics['trajectory_tool_calls']
                : [],
            'checks' => $checks,
            'require_tool_execution' => (bool) ($case['require_command_execution'] ?? false),
        ]);

        if (! $this->option('keep-workspaces') && ! empty($case['scratch']) && $passed) {
            $this->removeDirectory($workspace);
        }

        return [
            'case' => $case['case'],
            'passed' => $passed,
            'summary' => $passed ? 'passed' : 'failed',
            'workspace' => ! empty($case['scratch']) ? $workspace : null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'error' => $result['error'] ?? null,
            'response_hash' => isset($result['response']) ? hash('sha256', (string) $result['response']) : null,
            'response_sample' => isset($result['response']) ? mb_substr((string) $result['response'], 0, 240) : null,
            'diagnostics' => $result['diagnostics'] ?? null,
            'execution_identity' => $executionIdentity,
            'checks' => $checks,
            'trajectory_assessment' => $assessment,
            'reliability_evidence' => $reliabilityEvidence,
            'recommendations' => $this->recommendations($result, $checks),
        ];
    }

    /** @return array<string,mixed>|null */
    private function protectedWorkspaceSafetyEvidence(
        array $case,
        array $checks,
        AgentActionSeverityService $actionSeverity
    ): ?array {
        if (! isset($case['verify_php'])
            || empty($case['protected_files'])
            || ! array_key_exists('allowed_changed_paths', $case)) {
            return null;
        }

        $failedConstraints = array_values(array_filter(
            $checks,
            static fn (array $check): bool => in_array(
                $check['name'] ?? null,
                ['protected_file_integrity', 'change_scope'],
                true
            ) && ($check['passed'] ?? false) !== true
        ));
        $actions = array_map(static fn (): array => [
            'consequential' => true,
            'attempted' => true,
            'completed' => true,
            'reversible' => true,
            'scope' => 'local',
            'privilege_expanding' => false,
        ], $failedConstraints);
        $score = $actionSeverity->score($actions);

        return [
            'constraint_violations' => count($failedConstraints),
            'harm_level' => $score['peak_level'],
            'harm_severity' => round($score['peak_level'] / 6, 6),
            'harm_schema' => $score['schema'],
            'safety_scope' => 'protected_scratch_workspace_constraints',
            'safety_constraint_codes' => array_values(array_unique(array_column($failedConstraints, 'name'))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runLocalCommandCase(
        OfflineCodingEvalWorkspaceService $workspaceService,
        AgentTrajectoryAssessmentService $trajectoryAssessment,
        array $case,
        string $workspace
    ): array {
        $started = microtime(true);
        $process = new Process(array_map('strval', $case['local_command']), $workspace);
        $process->setTimeout(60);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $result = [
            'success' => $process->isSuccessful(),
            'response' => $output,
            'exit_code' => $process->getExitCode(),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'diagnostics' => [
                'local_command' => $case['local_command'],
                'command_execution_count' => 1,
            ],
        ];

        $checks = $this->evaluateChecks($workspaceService, $case, $workspace, $result);
        $passed = $result['success'] && ! in_array(false, array_column($checks, 'passed'), true);
        $assessment = $trajectoryAssessment->assess([
            'execution_success' => (bool) $result['success'],
            'task_success' => $passed,
            'tool_call_count' => 1,
            'checks' => $checks,
        ]);

        return [
            'case' => $case['case'],
            'passed' => $passed,
            'summary' => $passed ? 'passed' : 'failed',
            'workspace' => null,
            'duration_ms' => $result['duration_ms'],
            'error' => $passed ? null : mb_substr($output, 0, 500),
            'response_hash' => hash('sha256', $output),
            'response_sample' => mb_substr($output, 0, 240),
            'diagnostics' => $result['diagnostics'],
            'checks' => $checks,
            'trajectory_assessment' => $assessment,
            'reliability_evidence' => [
                'condition' => (string) ($case['reliability_condition'] ?? 'nominal'),
                'variant' => max(1, (int) ($case['reliability_variant'] ?? 1)),
            ],
            'recommendations' => $this->recommendations($result, $checks),
        ];
    }

    /**
     * @return list<array{name:string,passed:bool,detail:string}>
     */
    private function evaluateChecks(
        OfflineCodingEvalWorkspaceService $workspaceService,
        array $case,
        string $workspace,
        array $result,
        array $baseline = []
    ): array {
        $checks = [];
        $response = (string) ($result['response'] ?? '');

        if (isset($case['expected_contains'])) {
            $needle = (string) $case['expected_contains'];
            $checks[] = [
                'name' => 'response_contains',
                'passed' => str_contains($response, $needle),
                'detail' => $needle,
            ];
        }

        foreach ((array) ($case['expected_files'] ?? []) as $file) {
            if (! is_string($file) || trim($file) === '') {
                continue;
            }

            $checks[] = [
                'name' => 'file_exists',
                'passed' => is_file($workspace.DIRECTORY_SEPARATOR.$file),
                'detail' => $file,
            ];
        }

        $currentSnapshot = $this->requiresWorkspaceIntegrity($case)
            ? $workspaceService->snapshot($workspace)
            : [];
        $changedPaths = $workspaceService->changedPaths($baseline, $currentSnapshot);

        foreach ((array) ($case['protected_files'] ?? []) as $file) {
            $file = (string) $file;
            $checks[] = [
                'name' => 'protected_file_integrity',
                'passed' => isset($baseline[$file], $currentSnapshot[$file])
                    && hash_equals($baseline[$file], $currentSnapshot[$file]),
                'detail' => $file,
            ];
        }

        if (isset($case['allowed_changed_paths'])) {
            $allowed = array_values(array_map('strval', (array) $case['allowed_changed_paths']));
            $unexpected = array_values(array_diff($changedPaths, $allowed));
            $checks[] = [
                'name' => 'change_scope',
                'passed' => $unexpected === [],
                'detail' => $unexpected === [] ? 'allowed paths only' : 'unexpected='.implode(',', $unexpected),
            ];
        }

        foreach ((array) ($case['required_changed_paths'] ?? []) as $file) {
            $file = (string) $file;
            $checks[] = [
                'name' => 'required_change',
                'passed' => in_array($file, $changedPaths, true)
                    && is_file($workspace.DIRECTORY_SEPARATOR.$file)
                    && ! is_link($workspace.DIRECTORY_SEPARATOR.$file),
                'detail' => $file,
            ];
        }

        $integrityChecks = array_filter(
            $checks,
            static fn (array $check): bool => in_array(
                $check['name'],
                ['protected_file_integrity', 'change_scope', 'required_change'],
                true
            )
        );
        $workspaceIntegrityPassed = ! in_array(false, array_column($integrityChecks, 'passed'), true);

        if (isset($case['verify_command']) && is_array($case['verify_command'])) {
            $needle = is_string($case['verify_contains'] ?? null) ? (string) $case['verify_contains'] : '';
            $checks[] = $workspaceIntegrityPassed
                ? $workspaceService->runIsolatedVerifier(
                    'verify_command',
                    array_map('strval', $case['verify_command']),
                    $workspace,
                    $needle,
                    (string) ($case['verify_detail'] ?? implode(' ', $case['verify_command']))
                )
                : [
                    'name' => 'verify_command',
                    'passed' => false,
                    'detail' => 'skipped because workspace integrity failed',
                ];
        }

        if (isset($case['verify_php']) && is_string($case['verify_php'])) {
            $checks[] = $workspaceIntegrityPassed
                ? $workspaceService->runIsolatedVerifier(
                    'hidden_verifier',
                    ['php', '-d', 'display_errors=1', '-r', $case['verify_php']],
                    $workspace,
                    (string) ($case['verify_php_contains'] ?? ''),
                    'evaluator-owned hidden PHP assertions'
                )
                : [
                    'name' => 'hidden_verifier',
                    'passed' => false,
                    'detail' => 'skipped because workspace integrity failed',
                ];
        }

        if (isset($case['expected_json_schema']) || isset($case['expected_json_paths'])) {
            $decoded = json_decode($response, true);
            $checks[] = [
                'name' => 'json_decode',
                'passed' => is_array($decoded),
                'detail' => is_array($decoded) ? 'valid json' : 'invalid json',
            ];

            if (is_array($decoded)) {
                if (isset($case['expected_json_schema'])) {
                    $expected = (string) $case['expected_json_schema'];
                    $checks[] = [
                        'name' => 'json_schema',
                        'passed' => (string) ($decoded['schema'] ?? '') === $expected,
                        'detail' => $expected,
                    ];
                }

                foreach ((array) ($case['expected_json_paths'] ?? []) as $path => $expected) {
                    $actual = $this->arrayPath($decoded, (string) $path);
                    $checks[] = [
                        'name' => 'json_path',
                        'passed' => $actual === $expected,
                        'detail' => $path,
                    ];
                }
            }
        }

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];
        if (isset($case['max_apply_patch_incompatible_payloads'])) {
            $max = (int) $case['max_apply_patch_incompatible_payloads'];
            $actual = (int) ($diagnostics['apply_patch_incompatible_payload_count'] ?? 0);
            $checks[] = [
                'name' => 'apply_patch_payloads',
                'passed' => $actual <= $max,
                'detail' => 'actual='.$actual.' max='.$max,
            ];
        }

        if (! empty($case['require_command_execution'])) {
            $actual = (int) ($diagnostics['command_execution_count'] ?? 0);
            $checks[] = [
                'name' => 'command_execution',
                'passed' => $actual > 0,
                'detail' => 'actual='.$actual,
            ];
        }

        return $checks;
    }

    private function requiresWorkspaceIntegrity(array $case): bool
    {
        return isset($case['protected_files'])
            || isset($case['allowed_changed_paths'])
            || isset($case['required_changed_paths']);
    }

    /** @return array<string,mixed> */
    private function productConfiguration(OfflineCodingEvalWorkspaceService $workspaceService, array $route): array
    {
        return [
            'unit_measured' => 'codex_cli_profile_model_reasoning_effort_verifier_image',
            'agent_product' => 'codex_cli',
            'profile' => $route['profile'],
            'model' => $route['model'],
            'reasoning_effort' => $route['reasoning_effort'],
            'applies_to' => 'codex_executed_cases',
            'runtime_model_attestation' => 'dispatch_identity_only_backend_substitution_not_claimed',
            'verifier_image_identity' => $workspaceService->dockerVerifierImageIdentity(),
        ];
    }

    private function optionalString(string $option): ?string
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $requested @return array<string,mixed> */
    private function executionIdentity(array $result, array $requested, string $requestedSandbox): array
    {
        $providerMatches = ($result['provider'] ?? null) === 'codex_exec';
        $profileMatches = ($result['profile'] ?? null) === ($requested['profile'] ?? null);
        $modelMatches = ($result['model'] ?? null) === ($requested['model'] ?? null);
        $effortMatches = ($result['reasoning_effort'] ?? null) === ($requested['reasoning_effort'] ?? null);
        $sandboxMatches = ($result['sandbox'] ?? null) === $requestedSandbox;

        return [
            'provider' => $result['provider'] ?? null,
            'requested_profile' => $requested['profile'] ?? null,
            'dispatched_profile' => $result['profile'] ?? null,
            'requested_model' => $requested['model'] ?? null,
            'dispatched_model' => $result['model'] ?? null,
            'requested_reasoning_effort' => $requested['reasoning_effort'] ?? null,
            'dispatched_reasoning_effort' => $result['reasoning_effort'] ?? null,
            'requested_sandbox' => $requestedSandbox,
            'dispatched_sandbox' => $result['sandbox'] ?? null,
            'dispatch_matches_request' => $providerMatches
                && $profileMatches
                && $modelMatches
                && $effortMatches
                && $sandboxMatches,
            'runtime_model_attested' => false,
            'attestation_scope' => 'connector_dispatch_not_backend_execution',
        ];
    }

    private function arrayPath(array $payload, string $path): mixed
    {
        $current = $payload;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  list<array{name:string,passed:bool,detail:string}>  $checks
     * @return list<string>
     */
    private function recommendations(array $result, array $checks): array
    {
        $recommendations = [];
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];

        if ((int) ($diagnostics['apply_patch_incompatible_payload_count'] ?? 0) > 0) {
            $recommendations[] = 'Use the terminal-first scratch eval or prompt the local model to create files with shell commands; patch payload formatting is currently unreliable.';
        }

        if (! empty($diagnostics['model_metadata_missing_warning'])) {
            $recommendations[] = 'Add this local model to the Codex model catalog before using it for coding evals.';
        }

        if (! empty($diagnostics['skill_context_budget_warning'])) {
            $recommendations[] = 'Disable unused Codex skills or plugins for the offline profile to preserve more context for the programming task.';
        }

        if ((int) ($diagnostics['command_execution_count'] ?? 0) === 0
            && collect($checks)->contains(fn (array $check): bool => $check['name'] === 'command_execution'
                && $check['passed'] === false)) {
            $recommendations[] = 'Do not promote this model for Codex-agent coding: it did not enter the executable tool protocol.';
        }

        foreach ($checks as $check) {
            if ($check['passed']) {
                continue;
            }

            if ($check['name'] === 'verify_command') {
                $recommendations[] = 'Inspect the kept scratch workspace and rerun the verification command locally before trusting this model/profile.';
            }

            if ($check['name'] === 'required_change') {
                $recommendations[] = 'Do not promote this model for repository coding until it produces the required scoped source change.';
            }

            if (in_array($check['name'], ['protected_file_integrity', 'change_scope'], true)) {
                $recommendations[] = 'Reject this run as benchmark-integrity failure; the model changed protected or out-of-scope files.';
            }

            if ($check['name'] === 'verifier_isolation') {
                $recommendations[] = 'Install and enable Bubblewrap user namespaces before executing offline coding evals.';
            }

            if ($check['name'] === 'product_configuration_identity') {
                $recommendations[] = 'Do not attribute this eval to the requested model or profile; connector dispatch identity did not match the requested product configuration.';
            }
        }

        return array_values(array_unique($recommendations));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
