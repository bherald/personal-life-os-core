<?php

namespace App\Services;

use App\Services\Compute\ComputeExecutionCustodyService;
use App\Services\UnifiedCli\CliAgentAdmission;
use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliFrameworkServices;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliHostLifecycle;
use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliModelHostStatus;
use App\Services\UnifiedCli\CliModelRequest;
use App\Services\UnifiedCli\CliPromptCounter;
use App\Services\UnifiedCli\CliRouteResolver;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliSystemConfigService;
use App\Services\UnifiedCli\CliToolCatalog;
use App\Services\UnifiedCli\LosslessConversation;
use App\Services\UnifiedCli\PrimaryAgentLoop;
use App\Support\RuntimeBoolean;
use Closure;
use GuzzleHttp\Client;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One finite server-owned preparation plan on the existing authenticated DEV worker. */
class UpstreamPrivatePreparationWorkerService
{
    public const CYCLE_POLICY_KEY = 'upstream_preparation_worker_cycle';

    private const CYCLE_CURSOR_KEY = 'upstream_preparation_worker_cycle_cursor';

    public function __construct(
        private readonly UpstreamPrivatePreparationAdmissionService $admission,
        private readonly RepoDevMCPService $repo,
        private readonly RepoEditRecoveryService $recovery,
        private readonly AgentToolRegistryService $registry,
        private readonly ?Closure $runtimeFactory = null,
        private readonly ?Closure $frameworkInvoker = null,
    ) {}

    /** Existing scheduled metadata command consumes new work; no queue replay. */
    public function consumeScheduled(): array
    {
        $summary = ['schema' => 'plos.upstream_worker_cycle.v1', 'status' => 'disabled',
            'selected' => 0, 'invoked' => 0, 'held' => 0, 'automatic_replay_allowed' => false];
        try {
            $policy = $this->read(self::CYCLE_POLICY_KEY);
            $admission = $this->read(UpstreamPrivatePreparationAdmissionService::POLICY_KEY);
            if (($policy['enabled'] ?? false) !== true || ($admission['enabled'] ?? false) !== true
                || ($admission['worker_execution_enabled'] ?? false) !== true) {
                return $summary;
            }
            $limit = $policy['max_items'] ?? null;
            $wall = $policy['max_wall_seconds'] ?? null;
            if (! is_int($limit) || $limit < 1 || $limit > ($policy['budget_caps']['max_items'] ?? 0)
                || ! is_int($wall) || $wall < 1 || $wall > ($policy['budget_caps']['max_wall_seconds'] ?? 0)) {
                return $summary + ['reason_code' => 'cycle_budget_unqualified'];
            }
            $summary['status'] = 'bounded';
            $deadline = microtime(true) + $wall;
            for ($item = 0; $item < $limit && microtime(true) < $deadline; $item++) {
                // Advance an independent fair-selection cursor BEFORE invocation.
                // Metadata cadence/retry cursors and every work tombstone survive.
                $key = DB::transaction(function (): ?string {
                    $this->row(self::CYCLE_CURSOR_KEY)->insertOrIgnore(['section' => 'ops', 'config_key' => self::CYCLE_CURSOR_KEY,
                        'config_value' => '{}', 'data_type' => 'json', 'description' => 'Bounded worker selection only; never metadata retry authority.',
                        'created_at' => now(), 'updated_at' => now()]);
                    $cursor = $this->row(self::CYCLE_CURSOR_KEY)->lockForUpdate()->first();
                    $after = json_decode($cursor->config_value, true, flags: JSON_THROW_ON_ERROR)['after'] ?? '';
                    $works = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'like', 'upstream_source_work_%');
                    $key = (clone $works)->where('config_key', '>', $after)->orderBy('config_key')->value('config_key')
                        ?? $works->orderBy('config_key')->value('config_key');
                    if ($key === null) {
                        return null;
                    }
                    $encoded = json_encode(['after' => $key], JSON_THROW_ON_ERROR);
                    if ($this->row(self::CYCLE_CURSOR_KEY)->where('config_value', $cursor->config_value)
                        ->update(['config_value' => $encoded, 'updated_at' => now()]) !== 1
                        || $this->row(self::CYCLE_CURSOR_KEY)->value('config_value') !== $encoded) {
                        throw new RuntimeException('Worker selection cursor CAS failed.');
                    }

                    return $key;
                });
                if ($key === null) {
                    break;
                }
                $summary['selected']++;
                $grant = $this->read('upstream_source_admission_'.substr($key, -64));
                if (($grant['budgets']['wall_seconds'] ?? PHP_INT_MAX) > max(0, (int) ($deadline - microtime(true)))) {
                    $summary['held']++;

                    continue;
                }
                $launchBytes = $this->row('upstream_source_launch_'.substr($key, -64))->value('config_value');
                $launch = json_decode($launchBytes ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                $receipt = $this->read('upstream_source_attempt_'.substr($key, -64));
                if ($launchBytes === null && $receipt === []) {
                    $phase = 'launch';
                    $tool = 'upstream_launch_worker';
                    $arguments = ['workKey' => $key];
                    $identity = $grant['worker_launch']['caller'] ?? [];
                } elseif (($launch['outcome'] ?? null) === 'unknown' && ($receipt['outcome'] ?? null) === 'prepared') {
                    $phase = 'reconcile';
                    $tool = 'upstream_reconcile_worker';
                    $arguments = ['workKey' => $key, 'expectedLaunchHash' => hash('sha256', $launchBytes), 'dryRun' => false];
                    $identity = $grant['observer_caller'] ?? [];
                } elseif (($launch['outcome'] ?? null) === 'prepared'
                    && ($receipt['physical_reconciliation']['physical_cleanup_verified'] ?? false) === true) {
                    $ready = $this->read('upstream_source_ready_'.substr($key, -64));
                    $phase = 'ready_review';
                    $tool = 'upstream_ready_review';
                    $arguments = ['workKey' => $key];
                    if (DB::table('agent_review_queue')->where('agent_id', 'upstream-preparation')
                        ->where('review_type', 'upstream_preparation')->where('details->work_key', $key)->exists()) {
                        continue;
                    }
                    $identity = $ready['worker_caller'] ?? [];
                } else {
                    // Original UNKNOWN may only be observed, never relaunched.
                    $summary['held']++;

                    continue;
                }
                $descriptor = $grant['cycle_requests'][$phase] ?? [];
                $bytes = CliLocalBindings::readPrivateFile($descriptor['file'] ?? '');
                $request = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $key)
                    || hash('sha256', $bytes) !== ($descriptor['sha256'] ?? null) || ($request['tool'] ?? null) !== $tool
                    || ($request['approved'] ?? false) !== true
                    || ($request['arguments']['workKey'] ?? null) !== $key
                    || array_diff_key($request['caller_binding'] ?? [], ['arguments_sha256' => true]) !== array_diff_key($identity, ['arguments_sha256' => true])) {
                    $summary['held']++;

                    continue;
                }
                // Only fresh selectors change in a pinned approved template.
                // Independent caller/skill/rule/journal/registry admission remains
                // inside the existing authenticated framework dispatcher.
                $request['arguments'] = $arguments;
                $request['caller_binding']['arguments_sha256'] = hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR));
                $summary['invoked']++;
                $result = $this->frameworkInvoker === null
                    ? $this->dispatchCycleRequest($grant, $request) : ($this->frameworkInvoker)($request);
                if (($result['success'] ?? false) !== true) {
                    $summary['held']++;
                }
            }

            return $summary;
        } catch (\Throwable) {
            // Preserve durable intents and cursor. No exception/SQL/source leaks.
            return array_replace($summary, ['status' => 'held', 'reason_code' => 'cycle_admission_or_evidence_unavailable']);
        }
    }

    private function dispatchCycleRequest(array $grant, array $request): array
    {
        $binding = $grant['cycle_dispatch'] ?? [];
        CliLocalBindings::assertPrivateSqlite($binding['journal_path'] ?? '');
        if (($binding['session_id'] ?? null) !== ($request['caller_binding']['session_id'] ?? null)
            || ! is_string($binding['node'] ?? null) || hash_file('sha256', $binding['node']) !== ($binding['node_sha256'] ?? null)) {
            throw new RuntimeException('Actual recurring dispatcher binding is unqualified.');
        }
        $store = new CliStateStore($binding['journal_path']);
        if ($this->hash((new CliConfiguration($store))->effective($store->session($binding['session_id'])))
            !== ($binding['configuration_sha256'] ?? null)) {
            throw new RuntimeException('Exact recurring dispatcher configuration changed.');
        }

        // The existing SSH/local framework transport performs the invocation on
        // its exact table-owned target. DEV journal observation stays on DEV.
        return (new CliFrameworkServices($store, $binding['session_id'],
            realpath(base_path()), $binding['node'], $this->existingArtifacts($binding['artifact_directory'])))
            ->dispatchPreparedRequest($request);
    }

    /** Reuse the existing compute supervisor; public arguments select work, never shell. */
    public function launchWorker(string $workKey): array
    {
        $launchKey = 'upstream_source_launch_'.substr($workKey, -64);
        try {
            if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $workKey)) {
                return $this->held('launch_selector_invalid');
            }
            $grant = $this->read('upstream_source_admission_'.substr($workKey, -64));
            $launch = $grant['worker_launch'] ?? [];
            $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
            if (! is_array($caller) || $this->hash($launch['caller'] ?? []) !== $this->hash($caller)
                || ($this->read(UpstreamPrivatePreparationAdmissionService::POLICY_KEY)['worker_execution_enabled'] ?? false) !== true
                || ($launch['worker_root'] ?? null) !== ($grant['workspace']['worktree_root'] ?? null)
                || ($launch['framework_entrypoint'] ?? null) !== $launch['worker_root'].'/scripts/plos-cli/framework-service.php'
                || hash_file('sha256', $launch['php']) !== ($launch['php_sha256'] ?? null)
                || hash_file('sha256', $launch['framework_entrypoint']) !== ($launch['entrypoint_sha256'] ?? null)) {
                return $this->held('launch_binding_unqualified');
            }
            $requestBytes = CliLocalBindings::readPrivateFile($launch['request_file']);
            $request = json_decode($requestBytes, true, flags: JSON_THROW_ON_ERROR);
            if (hash('sha256', $requestBytes) !== ($launch['request_sha256'] ?? null)
                || ($request['tool'] ?? null) !== 'upstream_prepare_worker' || ($request['root'] ?? null) !== $launch['worker_root']
                || ($request['arguments'] ?? null) !== ['workKey' => $workKey, 'taskId' => $grant['task_id'],
                    'attempt' => $grant['attempt'], 'generation' => $grant['assignment_generation']]
                || $this->hash($request['caller_binding'] ?? []) !== $this->hash($grant['worker_preview_caller'] ?? [])) {
                return $this->held('launch_request_unqualified');
            }
            $router = app(ComputeRouterService::class);
            $instance = $router->getInstance($launch['compute_instance_id']);
            if ($instance === null || ! (bool) $instance->is_active || (int) ($instance->max_concurrent ?? 1) !== 1) {
                return $this->held('launch_instance_unqualified');
            }
            $this->assertOwner($grant);
            $command = escapeshellarg($launch['php']).' '.escapeshellarg($launch['framework_entrypoint']).' < '.escapeshellarg($launch['request_file']);
            $original = ['schema' => 'plos.upstream_worker_launch.v1', 'work_key' => $workKey,
                'admission_sha256' => $this->hash($grant), 'owner_session' => $grant['owner_session'],
                'command_sha256' => hash('sha256', $command), 'request_sha256' => $launch['request_sha256'],
                'framework_request_sha256' => hash('sha256', json_encode(array_diff_key($request, ['remote_operation' => true, 'approved' => true, 'control_existing' => true]), JSON_THROW_ON_ERROR)),
                'compute_instance_id' => $launch['compute_instance_id'], 'phase' => 'launch_intent', 'outcome' => 'unknown'];
            $encoded = json_encode($original, JSON_THROW_ON_ERROR);
            if ($this->row($launchKey)->insertOrIgnore(['section' => 'ops', 'config_key' => $launchKey, 'config_value' => $encoded,
                'data_type' => 'json', 'description' => 'Exact upstream outer supervisor; never automatic replay.', 'created_at' => now(), 'updated_at' => now()]) !== 1
                || $this->row($launchKey)->value('config_value') !== $encoded) {
                return $this->held('launch_replay_held');
            }
            ComputeRouterService::withRequiredInstance($launch['compute_instance_id'],
                fn () => $router->execute($instance, $command, $grant['budgets']['wall_seconds']),
                dispatchObserver: function (array $binding) use ($launchKey, $encoded, $original): void {
                    if (($binding['schema'] ?? null) !== 'plos.compute_dispatch_binding.v1'
                        || ($binding['instance_id'] ?? null) !== $original['compute_instance_id']
                        || ($binding['command_sha256'] ?? null) !== $original['command_sha256']) {
                        throw new RuntimeException('Outer supervisor identity differs from original work.');
                    }
                    $bound = json_encode($original + ['physical_binding' => $binding], JSON_THROW_ON_ERROR);
                    if ($this->row($launchKey)->where('config_value', $encoded)->update(['config_value' => $bound, 'updated_at' => now()]) !== 1
                        || $this->row($launchKey)->value('config_value') !== $bound) {
                        throw new RuntimeException('Durable outer binding did not reopen before dispatch.');
                    }
                });

            // A returned command status is not domain completion. The observer
            // consumes stored supervisor, journal and full artifact evidence.
            return ['status' => 'launched_observer_required', 'launch_sha256' => hash('sha256', $this->row($launchKey)->value('config_value')),
                'preparation_complete' => false, 'execution_authorized' => false, 'automatic_replay_allowed' => false];
        } catch (\Throwable) {
            return $this->held('launch_outcome_unknown');
        }
    }

    /** Independent observer; consumes original stored receipts, never relaunches a worker. */
    public function reconcileWorkerOutcome(string $workKey, string $expectedLaunchHash, bool $dryRun = true): array
    {
        try {
            if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $workKey)
                || ! preg_match('/^[a-f0-9]{64}$/D', $expectedLaunchHash)) {
                return $this->held('observer_selector_invalid');
            }

            return DB::transaction(function () use ($workKey, $expectedLaunchHash, $dryRun): array {
                $grant = $this->read('upstream_source_admission_'.substr($workKey, -64));
                $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
                if (! is_array($caller) || ! $this->observerCaller($grant['observer_caller'] ?? [], $caller, $workKey, $expectedLaunchHash, $dryRun)) {
                    return $this->held('observer_caller_unqualified');
                }
                $launchRow = $this->row('upstream_source_launch_'.substr($workKey, -64))->lockForUpdate()->first();
                $launch = $launchRow ? json_decode($launchRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
                $binding = $launch['physical_binding'] ?? [];
                $receiptRow = $this->row('upstream_source_attempt_'.substr($workKey, -64))->lockForUpdate()->first();
                $receipt = $receiptRow ? json_decode($receiptRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
                if ($launchRow === null || hash('sha256', $launchRow->config_value) !== $expectedLaunchHash
                    || ($launch['admission_sha256'] ?? null) !== $this->hash($grant) || ($launch['outcome'] ?? null) !== 'unknown'
                    || ($receipt['outcome'] ?? null) !== 'prepared' || ($receipt['admission_sha256'] ?? null) !== $this->hash($grant)) {
                    return $this->held('observer_original_binding_changed');
                }
                $physical = DB::table('task_custody_records')->where('id', $binding['custody_id'] ?? 0)->lockForUpdate()->first();
                $envelope = $physical ? json_decode($physical->result_envelope, true, flags: JSON_THROW_ON_ERROR) : [];
                $terminal = $envelope['terminal_receipt'] ?? [];
                if (($binding['schema'] ?? null) !== 'plos.compute_dispatch_binding.v1' || ($binding['instance_id'] ?? null) !== ($launch['compute_instance_id'] ?? null)
                    || ($binding['surface_ref'] ?? null) !== hash('sha256', $launch['compute_instance_id']).':0'
                    || count($terminal) !== 6 || $physical === null || $physical->surface !== ComputeExecutionCustodyService::SURFACE
                    || $physical->owner_token !== ($binding['owner_token'] ?? null) || $physical->surface_ref !== ($binding['surface_ref'] ?? null)
                    || ($envelope['instance_id'] ?? null) !== ($binding['instance_id'] ?? null)
                    || ($terminal['schema'] ?? null) !== 'plos.compute_terminal.v1' || ($terminal['cleanup_verified'] ?? false) !== true
                    || ($terminal['dispatched'] ?? false) !== true || ($terminal['exit_code'] ?? null) !== 0
                    || ($terminal['owner_token'] ?? null) !== $binding['owner_token']
                    || ($terminal['command_sha256'] ?? null) !== ($launch['command_sha256'] ?? null)) {
                    return $this->held('observer_physical_terminal_unqualified');
                }
                $plan = $grant['worker_plan'];
                CliLocalBindings::assertPrivateSqlite($plan['journal_path']);
                // Reopen the exact existing worker journal without schema writes.
                $journal = new \PDO('sqlite:'.$plan['journal_path']);
                $journal->exec('PRAGMA query_only = ON');
                $id = hash('sha256', json_encode([$grant['worker_preview_caller']['session_id'],
                    $grant['worker_preview_caller']['turn_id'], $grant['worker_preview_caller']['action_id']], JSON_THROW_ON_ERROR));
                $query = $journal->prepare('SELECT state,document FROM cli_action_receipts WHERE session_id = ? AND turn_id = ? AND action_id = ?');
                $query->execute([$grant['owner_session'], $grant['worker_preview_caller']['turn_id'], $id]);
                $outer = $query->fetch(\PDO::FETCH_ASSOC);
                $outerDocument = $outer ? json_decode($outer['document'], true, flags: JSON_THROW_ON_ERROR) : [];
                $outerResult = json_decode($outerDocument['result']['output'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                if (($outerDocument['action']['name'] ?? null) !== 'upstream_prepare_worker'
                    || ($outerDocument['action']['arguments']['request_sha256'] ?? null) !== ($launch['framework_request_sha256'] ?? null)
                    || ($outerDocument['instance_id'] ?? null) !== ($plan['journal_worker_id'] ?? null)
                    || ($outer['state'] ?? null) !== 'completed' || ($outerDocument['result']['success'] ?? false) !== true
                    || ($outerResult['result']['status'] ?? null) !== 'private_prepared'
                    || ($outerResult['result']['completion_artifact_sha256'] ?? null) !== ($receipt['completion_artifact']['sha256'] ?? null)) {
                    return $this->held('observer_worker_journal_unqualified');
                }
                $artifacts = $this->existingArtifacts($plan['artifact_directory']);
                $proof = json_decode($artifacts->read($receipt['completion_artifact']['sha256']), true, flags: JSON_THROW_ON_ERROR);
                if (($proof['work_key'] ?? null) !== $workKey || ($proof['admission_sha256'] ?? null) !== $this->hash($grant)
                    || ($proof['result']['status'] ?? null) !== 'completed' || ($proof['verification']['result']['success'] ?? false) !== true) {
                    return $this->held('observer_domain_artifact_unqualified');
                }
                if (($proof['model_io'] ?? []) === []) {
                    return $this->held('observer_model_terminal_unqualified');
                }
                foreach ($proof['model_io'] as $event) {
                    $io = $event['document']['event'] ?? [];
                    if (($io['completed'] ?? false) !== true || ($io['instance_id'] ?? null) !== ($proof['result']['instance_id'] ?? null)
                        || ($io['model'] ?? null) !== ($proof['result']['model'] ?? null)) {
                        return $this->held('observer_model_terminal_unqualified');
                    }
                    foreach (['request', 'response'] as $field) {
                        if (strlen($artifacts->read($io['evidence'][$field]['sha256'])) !== ($io['evidence'][$field]['bytes'] ?? null)) {
                            return $this->held('observer_model_terminal_unqualified');
                        }
                    }
                }
                foreach ($receipt['changed_files'] ?? [] as $path => $hash) {
                    if (str_contains($path, '..') || str_starts_with($path, '/') || is_link($grant['workspace']['worktree_root'].'/'.$path)
                        || hash_file('sha256', $grant['workspace']['worktree_root'].'/'.$path) !== $hash) {
                        return $this->held('observer_prepared_file_changed');
                    }
                }
                if (($receipt['changed_files'] ?? []) === [] || ($receipt['rollback_tokens'] ?? []) === []) {
                    return $this->held('observer_reversible_patch_unqualified');
                }
                foreach ($receipt['rollback_tokens'] as $token) {
                    $rollback = $this->recovery->load($token, historicalEvidence: true);
                    foreach ($rollback['files'] as $states) {
                        $path = $states['path'] ?? '';
                        if (! isset($receipt['changed_files'][$path]) || ($states['after']['sha256'] ?? null) !== $receipt['changed_files'][$path]) {
                            return $this->held('observer_rollback_evidence_changed');
                        }
                    }
                }
                $task = DB::table('distributed_tasks')->where('task_id', $grant['task_id'])->lockForUpdate()->first();
                $session = DB::table('task_custody_records')->where('id', $grant['session_custody_id'])->lockForUpdate()->first();
                $reservationRow = $this->row('upstream_workspace_reservation_'.hash('sha256', $grant['workspace']['worktree_root']))->lockForUpdate()->first();
                $reservation = $reservationRow ? json_decode($reservationRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
                if ($task === null || $this->hash(json_decode($task->payload, true, flags: JSON_THROW_ON_ERROR)['upstream_preparation'] ?? []) !== $this->hash([
                    'work_key' => $workKey, 'work_sha256' => $grant['work_sha256'], 'owner_session' => $grant['owner_session'], 'admission_sha256' => $this->hash($grant)])
                    || $task->status !== 'running' || (int) $task->retry_count !== $grant['attempt']
                    || (int) $task->assignment_generation !== $grant['assignment_generation'] || $session === null
                    || $session->surface !== 'agent_session' || $session->surface_ref !== $grant['owner_session']
                    || $session->owner_token !== $grant['session_owner_token'] || $session->released_at !== null
                    || ($reservation['outcome'] ?? null) !== 'prepared' || ($reservation['work_key'] ?? null) !== $workKey
                    || ($reservation['owner_session'] ?? null) !== $grant['owner_session']) {
                    return $this->held('observer_domain_owner_changed');
                }
                $proofHash = $this->hash(['physical' => $terminal, 'launch_sha256' => $expectedLaunchHash,
                    'worker_journal_sha256' => hash('sha256', $outer['document']), 'completion_sha256' => $receipt['completion_artifact']['sha256']]);
                if ($dryRun) {
                    return ['status' => 'dry_run_verified', 'proof_sha256' => $proofHash, 'physical_cleanup_verified' => true,
                        'domain_outcome' => 'private_prepared', 'automatic_replay_allowed' => false];
                }
                // Preserve exact reservation/session/task originals before scoped
                // CAS. No age, live PHP-FPM PID or generic release participates.
                $archive = $artifacts->captureBytes(json_encode(['reservation' => (array) $reservationRow,
                    'session' => (array) $session, 'task' => (array) $task], JSON_THROW_ON_ERROR));
                $closure = ['schema' => 'plos.upstream_worker_terminal_reconciliation.v1', 'proof_sha256' => $proofHash,
                    'originals' => $archive, 'physical_cleanup_verified' => true, 'domain_outcome' => 'private_prepared',
                    'automatic_replay_allowed' => false, 'resolved_at' => now()->toIso8601String()];
                $launch['outcome'] = 'prepared';
                $launch['closure'] = $closure;
                $receipt['physical_reconciliation'] = $closure;
                foreach ([[$launchRow, $launch], [$receiptRow, $receipt]] as [$row, $value]) {
                    $encoded = json_encode($value, JSON_THROW_ON_ERROR);
                    if (strlen($encoded) > 65535 || $this->row($row->config_key)->where('config_value', $row->config_value)
                        ->update(['config_value' => $encoded, 'updated_at' => now()]) !== 1 || $this->row($row->config_key)->value('config_value') !== $encoded) {
                        throw new RuntimeException('Independent observer record CAS failed.');
                    }
                }
                if (DB::table('task_custody_records')->where('id', $session->id)->where('surface', 'agent_session')
                    ->where('surface_ref', $grant['owner_session'])->where('owner_token', $grant['session_owner_token'])->whereNull('released_at')
                    ->update(['released_at' => now(), 'outcome' => 'success', 'result_envelope' => json_encode($closure, JSON_THROW_ON_ERROR),
                        'notification_state' => 'suppressed', 'updated_at' => now()]) !== 1
                    || DB::table('distributed_tasks')->where('id', $task->id)->where('status', 'running')
                        ->where('assigned_agent_id', $task->assigned_agent_id)->where('retry_count', $grant['attempt'])
                        ->where('assignment_generation', $grant['assignment_generation'])->where('payload', $task->payload)
                        ->update(['status' => 'completed', 'result' => json_encode($closure, JSON_THROW_ON_ERROR), 'updated_at' => now()]) !== 1
                    || $this->row($reservationRow->config_key)->where('config_value', $reservationRow->config_value)->delete() !== 1) {
                    throw new RuntimeException('Independent physical/domain owner CAS failed.');
                }
                $reopened = DB::table('task_custody_records')->where('id', $session->id)->first();
                if ($reopened->released_at === null || $reopened->owner_token !== $session->owner_token
                    || DB::table('distributed_tasks')->where('id', $task->id)->value('status') !== 'completed'
                    || $this->row($reservationRow->config_key)->exists()) {
                    throw new RuntimeException('Independent closure did not reopen exactly.');
                }

                return ['status' => 'reconciled', 'proof_sha256' => $proofHash, 'physical_cleanup_verified' => true,
                    'domain_outcome' => 'private_prepared', 'automatic_replay_allowed' => false];
            });
        } catch (\Throwable) {
            return $this->held('observer_evidence_unavailable');
        }
    }

    public function runWorker(string $workKey, string $taskId, int $attempt, int $generation): array
    {
        $intent = null;
        try {
            $preview = $this->admission->previewWorkerAdmission($workKey, $taskId, $attempt, $generation);
            if (($preview['binding_verified'] ?? false) !== true) {
                return $preview;
            }
            $policy = $this->read(UpstreamPrivatePreparationAdmissionService::POLICY_KEY);
            if (($policy['enabled'] ?? false) !== true || ($policy['worker_execution_enabled'] ?? false) !== true) {
                return $this->held('worker_execution_disabled');
            }
            $grant = $this->read('upstream_source_admission_'.substr($workKey, -64));
            $plan = $grant['worker_plan'] ?? [];
            // Large complete inputs use the existing private content-addressed
            // store; table grants retain references instead of TEXT truncation.
            if (isset($plan['messages_artifact'])) {
                $originals = $this->existingArtifacts($plan['artifact_directory']);
                $bytes = $originals->read($plan['messages_artifact']['sha256']);
                if (strlen($bytes) !== ($plan['messages_artifact']['bytes'] ?? null) || strlen($bytes) > $grant['budgets']['artifact_bytes']) {
                    return $this->held('worker_complete_messages_unqualified');
                }
                $plan['messages'] = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            }
            if (($plan['schema'] ?? null) !== 'plos.upstream_worker_plan.v1'
                || ! is_array($plan['messages'] ?? null) || ! array_is_list($plan['messages']) || $plan['messages'] === []
                || $this->hash($plan['messages']) !== ($plan['messages_sha256'] ?? null)
                || ! is_array($plan['source_inputs'] ?? null) || $plan['source_inputs'] === []
                || ! is_array($plan['actions'] ?? null) || $plan['actions'] === []
                || ! is_array($plan['verification'] ?? null) || $plan['verification'] === []
                || ! is_string($plan['skill'] ?? null) || $plan['skill'] === '') {
                return $this->held('worker_plan_unqualified');
            }
            $loaded = (new \ReflectionClass(self::class))->getFileName();
            if ($this->runtimeFactory === null && ($loaded !== base_path('app/Services/UpstreamPrivatePreparationWorkerService.php')
                || hash_file('sha256', $loaded) !== ($plan['worker_source_sha256'] ?? null))) {
                return $this->held('worker_loaded_source_unqualified');
            }
            $runtime = $this->runtimeFactory === null ? $this->runtime($grant, $plan)
                : ($this->runtimeFactory)($grant, $plan);
            if (($runtime['privacy_admitted'] ?? false) !== true) {
                return $this->held('worker_private_provider_unqualified');
            }
            $artifacts = $runtime['artifacts'];
            $sourceBytes = 0;
            foreach ($plan['source_inputs'] as $input) {
                $bytes = $artifacts->read($input['sha256']);
                $message = $plan['messages'][$input['message_index']] ?? [];
                if (strlen($bytes) !== ($input['bytes'] ?? null) || ($message['content'] ?? null) !== $bytes
                    || ($message['role'] ?? null) !== 'user') {
                    return $this->held('worker_source_input_changed');
                }
                $sourceBytes += strlen($bytes);
            }
            if ($sourceBytes > $grant['budgets']['artifact_bytes']) {
                return $this->held('worker_source_budget_exceeded');
            }
            foreach ($plan['messages'] as $message) {
                if (! in_array($message['role'] ?? null, ['system', 'developer', 'user'], true) || ! is_string($message['content'] ?? null)
                    || array_diff(array_keys($message), ['role', 'content']) !== []) {
                    return $this->held('worker_messages_unqualified');
                }
            }
            $route = $runtime['route'];
            // Force exact local counting for this bounded preparation route.
            if (($route['context_handling'] ?? null) === 'provider_reject') {
                return $this->held('worker_exact_tokenizer_unavailable');
            }
            // Full input is counted by the admitted selected-runtime tokenizer.
            // Provider-side rejection alone cannot prove this pre-intent budget.
            $tokens = ($runtime['counter'])($route, $plan['messages'], $runtime['tools']);
            if (! is_int($tokens) || $tokens < 0 || $tokens > $grant['budgets']['input_tokens']
                || ! is_int($route['max_iterations'] ?? null) || $route['max_iterations'] < 1
                || ! is_int($route['max_output_tokens'] ?? null) || $route['max_output_tokens'] < 1
                || $route['max_output_tokens'] > intdiv($grant['budgets']['output_tokens'], $route['max_iterations'])
                || ! is_int($route['max_tool_calls'] ?? null) || $route['max_tool_calls'] < 1
                || $route['max_tool_calls'] > count($plan['actions'])
                || $tokens > $route['context_window'] - $route['max_output_tokens']
                || ($runtime['admit'])('model', $route, []) !== true) {
                return $this->held('worker_model_budget_unqualified');
            }
            if (($plan['verification']['runner'] ?? null) !== 'phpunit-target'
                || ! is_string($plan['verification']['target'] ?? null) || ! str_starts_with($plan['verification']['target'], 'tests/')
                || hash_file('sha256', base_path($plan['verification']['target'])) !== ($plan['verification_sha256'] ?? null)
                || ($plan['schema_isolation_qualified'] ?? false) !== true) {
                return $this->held('worker_verification_unqualified');
            }
            foreach ($plan['actions'] as $action) {
                if (($runtime['methods'][$action['name']] ?? null) === 'applyRepoPatch') {
                    $check = $this->repo->applyRepoPatch($action['arguments']['patch'], checkOnly: true);
                    if (($check['check']['success'] ?? false) !== true || count($check['paths'] ?? []) > $grant['budgets']['changed_files']
                        || array_filter($check['paths'] ?? [], fn ($path) => str_starts_with($path, 'tests/') || $path === 'phpunit.xml') !== []) {
                        return $this->held('worker_patch_scope_unqualified');
                    }
                }
                if (($runtime['admit'])('tool', $route, $action) !== true) {
                    return $this->held('worker_tool_admission_refused');
                }
            }
            // Re-read the complete grant after slow token/runtime admission. The
            // existing begin serializes source, assignment and session custody.
            if ($this->hash($this->read('upstream_source_admission_'.substr($workKey, -64))) !== $this->hash($grant)) {
                return $this->held('worker_grant_changed');
            }
            $intent = $this->admission->begin($workKey, $grant['owner_session'], $taskId, $attempt, $generation);
            if (($intent['status'] ?? null) !== 'intent_recorded') {
                return $intent;
            }
            $started = hrtime(true);
            $totalTokens = 0;
            $assertCurrent = function () use ($grant, $workKey, $started, $policy): void {
                if ($this->hash($this->read(UpstreamPrivatePreparationAdmissionService::POLICY_KEY)) !== $this->hash($policy)
                    || (hrtime(true) - $started) / 1e9 >= $grant['budgets']['wall_seconds']
                    || $this->hash($this->read('upstream_source_admission_'.substr($workKey, -64))) !== $this->hash($grant)
                    || ($this->read(UpstreamPrivatePreparationAdmissionService::POLICY_KEY)['worker_execution_enabled'] ?? false) !== true
                    || ($this->read(UpstreamPrivatePreparationAdmissionService::POLICY_KEY)['enabled'] ?? false) !== true) {
                    throw new RuntimeException('Original worker scope changed or exhausted.');
                }
                $this->assertOwner($grant);
            };
            $loop = new PrimaryAgentLoop(
                function ($route, $messages, $tools) use ($runtime, $assertCurrent): array {
                    $assertCurrent();

                    return ($runtime['transport'])($route, $messages, $tools);
                },
                function ($route, $messages, $tools) use ($runtime, $grant, &$totalTokens): int {
                    $count = ($runtime['counter'])($route, $messages, $tools);
                    if (! is_int($count) || $count < 0 || $count > $grant['budgets']['input_tokens'] - $totalTokens) {
                        throw new RuntimeException('Complete-input cumulative budget is exhausted.');
                    }
                    $totalTokens += $count;

                    return $count;
                },
                function ($kind, $route, $action) use ($runtime, $assertCurrent): array {
                    $assertCurrent();

                    return ['allowed' => ($runtime['admit'])($kind, $route, $action) === true];
                }, $runtime['execute'], $runtime['journal'],
            );
            $result = $loop->run(new LosslessConversation($plan['messages']), $route, $runtime['tools'], $runtime['checkpoint']);
            $assertCurrent();
            // No prepared patch may alter its own protected verification target.
            foreach ($plan['actions'] as $action) {
                if (! is_string($action['name'] ?? null) || ! is_array($action['arguments'] ?? null)) {
                    throw new RuntimeException('Original finite tool plan changed.');
                }
            }
            // Deterministic verification is independent of the model's claims.
            $verification = $this->repo->runVerification(...$plan['verification']);
            if (($verification['result']['success'] ?? false) !== true) {
                throw new RuntimeException('Independent verification did not pass.');
            }
            $proof = ['schema' => 'plos.upstream_worker_completion.v1', 'work_key' => $workKey,
                'admission_sha256' => $intent['admission_sha256'], 'plan_sha256' => $this->hash($plan),
                'result' => $result, 'verification' => $verification,
                'action_results' => ($runtime['readback'])(), 'model_io' => ($runtime['model_readback'])(), 'input_tokens' => $totalTokens];
            $encoded = json_encode($proof, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > $grant['budgets']['artifact_bytes']) {
                throw new RuntimeException('Complete result cannot fit its artifact budget.');
            }
            $artifact = $artifacts->captureBytes($encoded);
            $readback = json_decode($artifacts->read($artifact['sha256']), true, flags: JSON_THROW_ON_ERROR);
            if ($readback !== $proof) {
                throw new RuntimeException('Complete artifact readback differs.');
            }

            return $this->finish($grant, $intent, $proof, $artifact);
        } catch (\Throwable) {
            if (($intent['status'] ?? null) === 'intent_recorded') {
                // Never release/reset an uncertain intent, workspace or session.
                $this->admission->markUnknown($workKey, Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT)['session_id'] ?? '', $intent['receipt_sha256']);

                return $this->held('worker_outcome_unknown');
            }

            return $this->held('worker_evidence_unavailable');
        }
    }

    /** Deliver only independently qualified server-owned evidence; never public publication. */
    public function deliverReadyReview(string $workKey): array
    {
        if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $workKey)) {
            return $this->held('ready_selector_invalid');
        }
        try {
            return DB::transaction(function () use ($workKey): array {
                $readyRow = $this->row('upstream_source_ready_'.substr($workKey, -64))->lockForUpdate()->first();
                $workRow = $this->row($workKey)->lockForUpdate()->first();
                $receiptRow = $this->row('upstream_source_attempt_'.substr($workKey, -64))->lockForUpdate()->first();
                $ready = $readyRow ? json_decode($readyRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
                $work = $workRow ? json_decode($workRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
                $receipt = $receiptRow ? json_decode($receiptRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
                $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
                if (! is_array($caller) || ($ready['schema'] ?? null) !== 'plos.upstream_ready_review.v1'
                    || $this->hash($ready['worker_caller'] ?? []) !== $this->hash($caller)
                    || ($ready['physical_terminal_qualified'] ?? false) !== true
                    || ($receipt['physical_reconciliation']['physical_cleanup_verified'] ?? false) !== true
                    || ($work['status'] ?? null) !== 'private_prepared' || ($receipt['outcome'] ?? null) !== 'prepared'
                    || ($ready['completion_sha256'] ?? null) !== ($receipt['completion_artifact']['sha256'] ?? null)
                    || ($work['completion_artifact']['sha256'] ?? null) !== $ready['completion_sha256']
                    || ! is_array($ready['qualified_evidence'] ?? null)) {
                    return $this->held('ready_review_evidence_unqualified');
                }
                $grant = $this->read('upstream_source_admission_'.substr($workKey, -64));
                $artifacts = $this->existingArtifacts($grant['worker_plan']['artifact_directory']);
                $completion = json_decode($artifacts->read($ready['completion_sha256']), true, flags: JSON_THROW_ON_ERROR);
                if (($completion['work_key'] ?? null) !== $workKey || ($completion['result']['status'] ?? null) !== 'completed'
                    || ($completion['verification']['result']['success'] ?? false) !== true) {
                    return $this->held('ready_review_artifact_unqualified');
                }
                foreach (['benefit', 'license', 'hardware_fit', 'source', 'tests', 'rollback'] as $gate) {
                    $evidence = $ready['qualified_evidence'][$gate] ?? [];
                    if (($evidence['qualified'] ?? false) !== true || ! is_string($evidence['owning_record'] ?? null)
                        || $evidence['owning_record'] === '' || ! preg_match('/^[a-f0-9]{64}$/D', $evidence['sha256'] ?? '')) {
                        return $this->held('ready_review_evidence_unqualified');
                    }
                    $document = json_decode($artifacts->read($evidence['sha256']), true, flags: JSON_THROW_ON_ERROR);
                    if (($document['schema'] ?? null) !== 'plos.upstream_qualified_evidence.v1' || ($document['gate'] ?? null) !== $gate
                        || ($document['work_key'] ?? null) !== $workKey || ($document['completion_sha256'] ?? null) !== $ready['completion_sha256']
                        || ($document['owning_record'] ?? null) !== $evidence['owning_record'] || ($document['qualified'] ?? false) !== true) {
                        return $this->held('ready_review_evidence_unqualified');
                    }
                }
                if (! is_string($ready['title'] ?? null) || $ready['title'] === '' || strlen($ready['title']) > 255
                    || ! is_string($ready['summary'] ?? null) || $ready['summary'] === '' || strlen($ready['summary']) > 4000) {
                    return $this->held('ready_review_packet_unqualified');
                }
                $digest = $this->hash(['work_key' => $workKey, 'completion_sha256' => $ready['completion_sha256'],
                    'qualified_evidence' => $ready['qualified_evidence'], 'title' => $ready['title'], 'summary' => $ready['summary']]);
                $matches = DB::table('agent_review_queue')->where('review_type', 'upstream_preparation')
                    ->where('agent_id', 'upstream-preparation')->where('details->work_key', $workKey)->limit(2)->lockForUpdate()->get();
                if ($matches->count() > 1) {
                    return $this->held('ready_review_duplicate_receipts');
                }
                if ($matches->count() === 1) {
                    $existing = $matches->first();
                    if ($existing->title !== $ready['title'] || $existing->summary !== $ready['summary']
                        || (json_decode($existing->details, true, flags: JSON_THROW_ON_ERROR)['delivery_sha256'] ?? null) !== $digest
                        || ! in_array($existing->status, ['pending', 'approved', 'rejected', 'ignored', 'auto_dismissed', 'expired'], true)) {
                        return $this->held('ready_review_receipt_changed');
                    }

                    return ['status' => 'unchanged', 'review_id' => (int) $existing->id, 'disposition' => $existing->status,
                        'public_writes' => false, 'pushover_allowed' => false];
                }
                $details = ['schema' => 'plos.upstream_ready_review_delivery.v1', 'work_key' => $workKey,
                    'completion_sha256' => $ready['completion_sha256'], 'qualified_evidence' => $ready['qualified_evidence'],
                    'delivery_sha256' => $digest, 'public_approval' => ['status' => 'not_requested', 'exact_target' => null, 'exact_content' => null]];
                $encoded = json_encode($details, JSON_THROW_ON_ERROR);
                $id = DB::table('agent_review_queue')->insertGetId(['review_type' => 'upstream_preparation', 'agent_id' => 'upstream-preparation',
                    'status' => 'pending', 'priority' => 0, 'title' => $ready['title'], 'summary' => $ready['summary'],
                    'details' => $encoded, 'token' => bin2hex(random_bytes(16)), 'created_at' => now(), 'updated_at' => now()]);
                $stored = DB::table('agent_review_queue')->where('id', $id)->first();
                if ($stored === null || $stored->details !== $encoded || $stored->status !== 'pending') {
                    throw new RuntimeException('Ready Review exact readback failed.');
                }

                return ['status' => 'created', 'review_id' => (int) $id, 'disposition' => 'pending',
                    'public_writes' => false, 'pushover_allowed' => false];
            });
        } catch (\Throwable) {
            return $this->held('ready_review_evidence_unavailable');
        }
    }

    private function runtime(array $grant, array $plan): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $framework = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->value('config_value');
        $framework = json_decode($framework ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $journal = $framework['worker_receipts'] ?? [];
        if (($journal['session_id'] ?? null) !== $grant['owner_session'] || ($journal['turn_id'] ?? null) !== ($caller['turn_id'] ?? null)
            || ($journal['path'] ?? null) !== ($plan['journal_path'] ?? null)
            || ($journal['worker_id'] ?? null) !== ($plan['journal_worker_id'] ?? null)) {
            throw new RuntimeException('Actual configured worker journal is unqualified.');
        }
        CliLocalBindings::assertPrivateSqlite($journal['path']);
        $store = new CliStateStore($journal['path']);
        $session = $store->session($grant['owner_session']);
        $this->assertPristineLaunchJournal($store, $session, $grant, $plan, $caller);
        $artifacts = $this->existingArtifacts($plan['artifact_directory']);
        $bindings = CliLocalBindings::fromFile($plan['bindings_file']);
        $http = new Client;
        $controller = json_decode(CliLocalBindings::readPrivateFile($plan['controller_binding_file']), true, flags: JSON_THROW_ON_ERROR);
        $connection = new CliModelHostStatus($store, $session['id'], $bindings, $artifacts, $http, $controller);
        $admission = new CliAgentAdmission($store, $bindings, $artifacts, $http,
            fn () => $connection->status() + ['controller_identity' => $connection->identity()]);
        $configuration = new CliConfiguration($store);
        $configurationHash = $this->hash($configuration->effective($session));
        $candidates = array_values(array_filter((new CliRouteResolver($configuration))->inspect($session)['candidates'],
            fn ($candidate) => $candidate['id'] === $plan['candidate_id'] && $candidate['configuration_eligible']));
        if (count($candidates) !== 1 || $configurationHash !== ($plan['configuration_sha256'] ?? null)) {
            throw new RuntimeException('Exact table-owned worker route is unqualified.');
        }
        $providerPolicy = new OfflinePolicyService(systemConfig: new CliSystemConfigService($store, $session['id']));
        if (! RuntimeBoolean::isTrue($candidates[0]['instance']['allows_private_data'] ?? false)
            || ! $providerPolicy->evaluateProvider($candidates[0]['instance'], context: ['_audit' => false])->allowed) {
            throw new RuntimeException('Current private-data/profile admission refused the exact provider.');
        }
        $decision = $admission->admit($session, $candidates[0]);
        if (($decision['allowed'] ?? false) !== true || $this->hash($decision['route']) !== ($plan['route_sha256'] ?? null)) {
            throw new RuntimeException('Current owned runtime admission failed.');
        }
        $route = $decision['route'];
        $skillPath = SkillLoaderService::configuredSkillsBasePath().'/'.$plan['skill'].'/SKILL.md';
        $skillBytes = file_get_contents($skillPath);
        if (! is_string($skillBytes) || hash('sha256', $skillBytes) !== ($plan['skill_sha256'] ?? null)
            || ! array_filter($plan['messages'], fn ($message) => in_array($message['role'] ?? null, ['system', 'developer'], true) && ($message['content'] ?? null) === $skillBytes)) {
            throw new RuntimeException('Complete scoped skill instructions were not preserved.');
        }
        $contract = $this->registry->resolveToolContractForAgent($plan['skill']);
        $tools = [];
        $methods = [];
        $catalog = new CliToolCatalog($configuration);
        foreach ($plan['actions'] as $action) {
            $definition = $contract['tools'][$action['name']] ?? [];
            if (($contract['skill_found'] ?? false) !== true || $contract['unresolved_declared_tools'] !== []
                || ($definition['service'] ?? null) !== RepoDevMCPService::class
                || ! in_array($definition['method'] ?? null, ['applyRepoPatch', 'runVerification', 'restoreRepoEdit'], true)
                || ! RuntimeBoolean::isTrue($definition['allows_private_data'] ?? false)
                || ($definition['requires_confirmation'] ?? true) !== false) {
                throw new RuntimeException('Scoped repo tool contract is unqualified.');
            }
            $methods[$action['name']] = $definition['method'];
            $tools[$action['name']] = ['type' => 'function', 'function' => ['name' => $action['name'],
                'parameters' => $catalog->schema($definition['parameters'])]];
        }
        $context = ['agent_id' => $plan['skill'], 'session_id' => $grant['owner_session'], 'ds_profile' => 'agent_dev_ds'];
        $admit = function ($kind, $selected, $action) use ($store, $session, $configuration, $configurationHash, $admission, $candidates, $plan, $route, $context): bool {
            $current = $store->session($session['id']);
            if ($selected !== $route || $this->hash($configuration->effective($current)) !== $configurationHash
                || ($admission->admit($current, $candidates[0])['route'] ?? null) !== $route) {
                return false;
            }
            if ($kind === 'model') {
                return true;
            }
            $allowed = array_filter($plan['actions'], fn ($expected) => ($expected['name'] ?? null) === ($action['name'] ?? null)
                && $this->hash($expected['arguments'] ?? []) === $this->hash($action['arguments'] ?? []));

            return count($allowed) === 1 && ($this->registry->executeTool($action['name'], $action['arguments'], $context, authorizeOnly: true)['authorized'] ?? false) === true;
        };
        $request = new CliModelRequest($store, $session['id'], $artifacts, $bindings, $http);
        $counter = CliPromptCounter::fromBindingsFile($store, $session['id'], $artifacts, $plan['tokenizer_bindings_file']);
        $keeper = (new CliHostLifecycle($store, $session['id'], $connection))->keeper($route['controller_target'], $route['model']);
        $modelEventsBefore = array_column($store->events($session['id']), 'id');
        $modelEventCursor = $modelEventsBefore === [] ? 0 : max($modelEventsBefore);
        $actionResults = [];
        $actionIds = [];

        return ['privacy_admitted' => true, 'route' => $route, 'methods' => $methods, 'tools' => array_values($tools), 'artifacts' => $artifacts, 'admit' => $admit,
            'counter' => $counter->count(...),
            'transport' => fn ($selected, $messages, $nativeTools) => $request->complete($selected, $messages, $nativeTools, $keeper),
            'execute' => function ($action) use ($context, &$actionResults): array {
                $result = $this->registry->executeTool($action['name'], $action['arguments'], $context);
                $actionResults[] = $result;

                return ['success' => ($result['success'] ?? false) === true, 'output' => json_encode($result, JSON_THROW_ON_ERROR)];
            },
            'journal' => function ($phase, $action, $selected, $result) use ($store, $session, $caller, &$actionIds): bool {
                if ($phase === 'start') {
                    $store->startAction($session['id'], $caller['turn_id'], $action, $selected);
                    $actionIds[] = $action['id'];
                } else {
                    $store->completeAction($session['id'], $caller['turn_id'], $action['id'], $result);
                }

                return true;
            },
            'checkpoint' => function ($messages) use ($store, $session): void {
                $current = $store->session($session['id']);
                $current['messages'] = $messages;
                $store->saveSession($current);
            },
            'model_readback' => function () use ($store, $session, $modelEventCursor, $route, $artifacts): array {
                $events = array_values(array_filter($store->events($session['id']), fn ($event) => $event['type'] === 'model_io' && $event['id'] > $modelEventCursor));
                if ($events === []) {
                    throw new RuntimeException('No original model terminal I/O receipt.');
                }
                foreach ($events as $event) {
                    $io = $event['document']['event'];
                    if (($io['completed'] ?? false) !== true || ($io['instance_id'] ?? null) !== $route['instance_id'] || ($io['model'] ?? null) !== $route['model']) {
                        throw new RuntimeException('Original model I/O outcome is unknown.');
                    }
                    foreach (['request', 'response'] as $field) {
                        $bytes = $artifacts->read($io['evidence'][$field]['sha256']);
                        if (strlen($bytes) !== ($io['evidence'][$field]['bytes'] ?? null)) {
                            throw new RuntimeException('Complete native model I/O readback changed.');
                        }
                    }
                }

                return $events;
            },
            'readback' => function () use ($store, $session, $caller, &$actionResults, &$actionIds): array {
                $receipts = $store->actionReceipts($session['id'], $caller['turn_id']);
                foreach ($actionIds as $id) {
                    $receipt = $receipts[$id] ?? [];
                    if ($receipt['state'] !== 'completed' || ($receipt['document']['result']['success'] ?? false) !== true) {
                        throw new RuntimeException('An action retains an uncertain outcome.');
                    }
                }

                return $actionResults;
            }];
    }

    /** Native framework launch and its inner worker share one dedicated journal. */
    private function assertPristineLaunchJournal(CliStateStore $store, array $session, array $grant, array $plan, array $caller): void
    {
        $launch = $this->read('upstream_source_launch_'.substr($grant['work_key'], -64));
        $parent = $grant['worker_launch']['caller'] ?? [];
        $expected = [
            ['caller' => $parent, 'tool' => 'upstream_launch_worker', 'request' => $grant['worker_launch']['parent_framework_request_sha256'] ?? null],
            ['caller' => $caller, 'tool' => 'upstream_prepare_worker', 'request' => $launch['framework_request_sha256'] ?? null],
        ];
        $receipts = $store->actionReceipts($session['id'], $caller['turn_id']);
        if (($session['messages'] ?? []) !== [] || count($session['turns'] ?? []) !== 1
            || ($session['turns'][0]['id'] ?? null) !== $caller['turn_id'] || ($session['turns'][0]['status'] ?? null) !== 'inProgress'
            || ($session['framework_worker'] ?? null) !== ['worker_id' => $plan['journal_worker_id'], 'root' => realpath(base_path())]
            || $this->hash($caller) !== $this->hash($grant['worker_preview_caller'] ?? []) || count($receipts) !== 2
            || ($launch['schema'] ?? null) !== 'plos.upstream_worker_launch.v1' || ($launch['work_key'] ?? null) !== $grant['work_key']
            || ($launch['outcome'] ?? null) !== 'unknown' || ($launch['admission_sha256'] ?? null) !== $this->hash($grant)
            || ($launch['physical_binding']['schema'] ?? null) !== 'plos.compute_dispatch_binding.v1'
            || ($parent['arguments_sha256'] ?? null) !== hash('sha256', json_encode(['workKey' => $grant['work_key']], JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Preparation requires an exact pristine launch and worker journal lineage.');
        }
        $ids = [];
        foreach ($expected as $entry) {
            foreach (['session_id', 'turn_id', 'action_id'] as $field) {
                if (! is_string($entry['caller'][$field] ?? null) || $entry['caller'][$field] === '') {
                    throw new RuntimeException('Preparation requires an exact pristine launch and worker journal lineage.');
                }
            }
            $id = hash('sha256', json_encode([$entry['caller']['session_id'], $entry['caller']['turn_id'], $entry['caller']['action_id']], JSON_THROW_ON_ERROR));
            $receipt = $receipts[$id] ?? [];
            if (! preg_match('/^[a-f0-9]{64}$/D', $entry['request'] ?? '') || in_array($id, $ids, true)
                || ($receipt['state'] ?? null) !== 'started' || ($receipt['document']['instance_id'] ?? null) !== $plan['journal_worker_id']
                || ($receipt['document']['action']['name'] ?? null) !== $entry['tool']
                || ($receipt['document']['action']['arguments'] ?? null) !== ['request_sha256' => $entry['request']]) {
                throw new RuntimeException('Preparation requires an exact pristine launch and worker journal lineage.');
            }
            $ids[] = $id;
        }
    }

    private function finish(array $grant, array $intent, array $proof, array $artifact): array
    {
        // Do not equate a model final response with a tested reversible patch.
        $recoveryTokens = [];
        $changedFiles = [];
        foreach ($proof['action_results'] as $action) {
            $result = $action['result'] ?? [];
            if (($result['applied'] ?? false) === true) {
                $token = $result['recovery']['token'] ?? '';
                $packet = $this->recovery->load($token, historicalEvidence: true);
                if (($packet['token'] ?? null) !== $token) {
                    throw new RuntimeException('Original rollback packet is unavailable.');
                }
                $recoveryTokens[] = $token;
                foreach ($packet['files'] as $states) {
                    $path = $states['path'] ?? '';
                    if (! is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')
                        || str_starts_with($path, 'tests/') || $path === 'phpunit.xml') {
                        throw new RuntimeException('Protected verification or unsafe path changed.');
                    }
                    $absolute = base_path($path);
                    if (! is_file($absolute) || is_link($absolute) || hash_file('sha256', $absolute) !== ($states['after']['sha256'] ?? null)) {
                        throw new RuntimeException('Actual prepared file differs from rollback evidence.');
                    }
                    $changedFiles[$path] = hash_file('sha256', $absolute);
                }
            }
        }
        if ($recoveryTokens === [] || count($changedFiles) > $grant['budgets']['changed_files']) {
            throw new RuntimeException('A bounded reversible patch was not read back.');
        }

        return DB::transaction(function () use ($grant, $intent, $artifact, $changedFiles, $recoveryTokens): array {
            $workKey = $grant['work_key'];
            $receiptKey = 'upstream_source_attempt_'.substr($workKey, -64);
            $receiptRow = $this->row($receiptKey)->lockForUpdate()->first();
            $receipt = $receiptRow ? json_decode($receiptRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
            $workRow = $this->row($workKey)->lockForUpdate()->first();
            $reservationKey = 'upstream_workspace_reservation_'.hash('sha256', $grant['workspace']['worktree_root']);
            $reservationRow = $this->row($reservationKey)->lockForUpdate()->first();
            $reservation = $reservationRow ? json_decode($reservationRow->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
            $this->assertOwner($grant);
            if ($receiptRow === null || ! hash_equals($intent['receipt_sha256'], hash('sha256', $receiptRow->config_value))
                || ($receipt['outcome'] ?? null) !== 'unknown' || ($receipt['owner_session'] ?? null) !== $grant['owner_session']
                || ($receipt['admission_sha256'] ?? null) !== $intent['admission_sha256']
                || $workRow === null || hash('sha256', $workRow->config_value) !== $grant['work_sha256']
                || $reservationRow === null || ($reservation['work_key'] ?? null) !== $workKey
                || ($reservation['owner_session'] ?? null) !== $grant['owner_session']
                || hash('sha256', $reservationRow->config_value) !== ($receipt['workspace_reservation_sha256'] ?? null)) {
                throw new RuntimeException('Original terminal compare-and-swap binding changed.');
            }
            $terminal = $receipt + ['completion_artifact' => $artifact, 'changed_files' => $changedFiles, 'rollback_tokens' => $recoveryTokens];
            $terminal['phase'] = 'private_prepared';
            $terminal['outcome'] = 'prepared';
            $terminal['preparation_complete'] = true;
            $terminal['completed_at'] = now()->toIso8601String();
            $reservation['outcome'] = 'prepared';
            $reservation['completion_artifact'] = $artifact;
            $work = json_decode($workRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $work['status'] = 'private_prepared';
            $work['completion_artifact'] = $artifact;
            foreach ([[$receiptRow, $terminal], [$reservationRow, $reservation], [$workRow, $work]] as [$row, $value]) {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR);
                if (strlen($encoded) > 65535 || $this->row($row->config_key)->where('config_value', $row->config_value)
                    ->update(['config_value' => $encoded, 'updated_at' => now()]) !== 1
                    || $this->row($row->config_key)->value('config_value') !== $encoded) {
                    throw new RuntimeException('Exact terminal readback failed.');
                }
            }

            // The original work/receipt/reservation are retained tombstones.
            // Root still owns source integration/deployment and final-ready Review.
            return ['status' => 'private_prepared', 'preparation_complete' => true, 'execution_authorized' => false,
                'operator_actionable' => false, 'pushover_allowed' => false, 'completion_artifact_sha256' => $artifact['sha256'],
                'ready_review' => 'held_for_independent_benefit_license_hardware_and_release_evidence'];
        });
    }

    private function assertOwner(array $grant): void
    {
        $work = $this->row($grant['work_key'])->value('config_value');
        $workData = json_decode($work ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $checkKey = str_replace('ops.', '', $workData['check_key'] ?? '');
        if (! is_string($work) || hash('sha256', $work) !== $grant['work_sha256']
            || hash('sha256', $this->row($checkKey)->value('config_value') ?? '') !== $grant['check_sha256']) {
            throw new RuntimeException('Complete source or original check changed.');
        }
        $agent = DB::table('distributed_agents')->where('agent_id', $grant['agent_id'])->lockForUpdate()->first();
        $task = DB::table('distributed_tasks')->where('task_id', $grant['task_id'])->lockForUpdate()->first();
        $custody = DB::table('task_custody_records')->select('*')->selectRaw('(expires_at > CURRENT_TIMESTAMP) AS lease_current')
            ->where('id', $grant['session_custody_id'])->lockForUpdate()->first();
        if ($agent === null || $agent->node_name !== $grant['node_name'] || ! in_array($agent->status, ['online', 'busy'], true)
            || $task === null || (int) $task->assigned_agent_id !== (int) $agent->id || $task->task_type !== UpstreamPrivatePreparationAdmissionService::TASK_TYPE || $task->status !== 'running' || (int) $task->retry_count !== $grant['attempt']
            || (int) $task->assignment_generation !== $grant['assignment_generation']
            || $this->hash((json_decode($task->payload, true, flags: JSON_THROW_ON_ERROR)['upstream_preparation'] ?? [])) !== $this->hash([
                'work_key' => $grant['work_key'], 'work_sha256' => $grant['work_sha256'],
                'owner_session' => $grant['owner_session'], 'admission_sha256' => $this->hash($grant)])
            || $custody === null || $custody->surface !== 'agent_session' || $custody->surface_ref !== $grant['owner_session']
            || $custody->owner_token !== $grant['session_owner_token'] || $custody->released_at !== null
            || (int) $custody->lease_current !== 1) {
            throw new RuntimeException('Original upstream session or assignment custody changed.');
        }
    }

    private function existingArtifacts(string $directory): CliArtifactStore
    {
        if (! is_dir($directory) || is_link($directory) || realpath($directory) !== $directory
            || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Original private artifact directory is unavailable.');
        }

        return new CliArtifactStore($directory);
    }

    private function observerCaller(array $expected, array $caller, string $workKey, string $launchHash, bool $dryRun): bool
    {
        // The randomly generated supervisor owner is bound after launch. Pin
        // observer identity in the immutable grant, while the authenticated
        // framework checks the exact fresh selector arguments independently.
        foreach (['session_id', 'turn_id', 'action_id', 'approval_scope_sha256'] as $field) {
            if (! is_string($expected[$field] ?? null) || $expected[$field] === '' || ($caller[$field] ?? null) !== $expected[$field]) {
                return false;
            }
        }
        $arguments = ['workKey' => $workKey, 'expectedLaunchHash' => $launchHash, 'dryRun' => $dryRun];

        return ($caller['arguments_sha256'] ?? null) === hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR));
    }

    private function read(string $key): array
    {
        return json_decode($this->row($key)->value('config_value') ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    }

    private function row(string $key): Builder
    {
        return DB::table('system_configs')->where('section', 'ops')->where('config_key', $key);
    }

    private function hash(array $value): string
    {
        // Same stable identity convention as existing admission grants.
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$entry) {
                if (is_array($entry)) {
                    $entry = $normalize($entry);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }

    private function held(string $reason): array
    {
        return ['status' => 'hold', 'reason_code' => $reason, 'execution_authorized' => false,
            'preparation_complete' => false, 'operator_actionable' => false, 'pushover_allowed' => false];
    }
}
