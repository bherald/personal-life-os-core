<?php

namespace App\Services;

use App\Services\Custody\TaskCustodyService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/** Backend admission only: never dispatches an agent, model, tool or schedule. */
class UpstreamPrivatePreparationAdmissionService
{
    public const POLICY_KEY = 'upstream_private_preparation_admission_policy';

    public const POLICY_SCHEMA = 'plos.upstream_private_preparation_admission_policy.v1';

    public const GRANT_SCHEMA = 'plos.upstream_private_preparation_grant.v1';

    public const RECEIPT_SCHEMA = 'plos.upstream_private_preparation_attempt.v1';

    public const TASK_TYPE = 'upstream_private_preparation';

    public function __construct(
        private readonly TaskCustodyService $custody,
        private readonly RepoDevMCPService $repoDev,
    ) {}

    /**
     * The identifiers are selectors, not authority. All bindings come from
     * uncached, locked server-owned rows. An existing intent never admits replay.
     *
     * @return array<string,mixed>
     */
    public function begin(string $workKey, string $sessionId, string $taskId, int $attempt, int $generation): array
    {
        if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $workKey)
            || $sessionId === '' || $taskId === '' || $attempt < 0 || $generation < 1) {
            return $this->hold('admission_selector_invalid');
        }

        try {
            return DB::transaction(function () use ($workKey, $sessionId, $taskId, $attempt, $generation): array {
                $validated = $this->validateLocked($workKey, $sessionId, $taskId, $attempt, $generation);
                if (($validated['status'] ?? null) === 'hold') {
                    return $validated;
                }
                $grant = $validated['grant'];
                $workspace = $validated['workspace'];
                $workspaceKey = $validated['workspace_key'];
                $receiptKey = $validated['receipt_key'];
                $bindingHash = $validated['binding_hash'];
                $reservation = ['schema' => 'plos.upstream_private_workspace_reservation.v1',
                    'outcome' => 'unknown', 'work_key' => $workKey, 'admission_sha256' => $bindingHash,
                    'owner_session' => $sessionId, 'task_id' => $taskId,
                    'workspace' => $workspace['proof'], 'reserved_at' => now()->toIso8601String()];
                $reservationJson = json_encode($reservation, JSON_THROW_ON_ERROR);
                if (strlen($reservationJson) > 65535) {
                    return $this->hold('attempt_storage_limit');
                }
                if ($this->row($workspaceKey)->insertOrIgnore(['section' => 'ops', 'config_key' => $workspaceKey,
                    'config_value' => $reservationJson, 'data_type' => 'json',
                    'description' => 'Unknown physical upstream workspace owner; no TTL or automatic takeover.',
                    'created_at' => now(), 'updated_at' => now()]) !== 1) {
                    return $this->hold('workspace_dispatch_held');
                }
                $reserved = $this->row($workspaceKey)->first();
                if ($reserved === null || $this->digest($this->decode($reserved->config_value)) !== $this->digest($reservation)) {
                    return $this->hold('workspace_reservation_readback_unqualified');
                }
                $receipt = ['schema' => self::RECEIPT_SCHEMA, 'phase' => 'dispatch_intent',
                    'outcome' => 'unknown', 'work_key' => $workKey, 'work_sha256' => $grant['work_sha256'],
                    'source_sha256' => $grant['source_sha256'], 'check_sha256' => $grant['check_sha256'],
                    'admission_sha256' => $bindingHash, 'owner_session' => $sessionId, 'task_id' => $taskId,
                    'agent_id' => $grant['agent_id'], 'attempt' => $attempt, 'assignment_generation' => $generation,
                    'session_custody_id' => $grant['session_custody_id'], 'workspace' => $workspace,
                    'workspace_reservation_key' => 'ops.'.$workspaceKey,
                    'workspace_reservation_sha256' => hash('sha256', $reserved->config_value),
                    'budgets' => $grant['budgets'], 'intent_at' => now()->toIso8601String(),
                    'operator_actionable' => false, 'pushover_allowed' => false, 'preparation_complete' => false];
                $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
                if (strlen($encoded) > 65535) {
                    return $this->hold('attempt_storage_limit');
                }
                if ($this->row($receiptKey)->insertOrIgnore(['section' => 'ops', 'config_key' => $receiptKey,
                    'config_value' => $encoded, 'data_type' => 'json', 'description' => 'Upstream dispatch intent; never automatic replay.',
                    'created_at' => now(), 'updated_at' => now()]) !== 1) {
                    return $this->hold('dispatch_replay_held');
                }
                $stored = $this->row($receiptKey)->first();
                if ($stored === null || ! hash_equals($this->digest($receipt), $this->digest($this->decode($stored->config_value)))) {
                    // Commit any published intent and hold; never rollback it into retry permission.
                    return $this->hold('attempt_readback_unqualified');
                }

                return ['status' => 'intent_recorded', 'receipt_key' => 'ops.'.$receiptKey,
                    'receipt_sha256' => hash('sha256', $stored->config_value), 'admission_sha256' => $bindingHash,
                    'budgets' => $grant['budgets'], 'operator_actionable' => false,
                    'preparation_complete' => false, 'execution_authorized' => false];
            });
        } catch (\Throwable) {
            // A failed commit/readback may already have persisted: the next begin
            // must inspect the durable key, not assume failure means non-dispatch.
            return $this->hold('admission_evidence_unavailable');
        }
    }

    /** Inspect an authenticated actual worker; never publishes intent or execution authority. */
    public function previewWorkerAdmission(string $workKey, string $taskId, int $attempt, int $generation): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        if (! is_array($caller) || count($caller) !== 5
            || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []) {
            return $this->hold('worker_caller_unqualified');
        }
        foreach (['session_id', 'turn_id', 'action_id'] as $field) {
            if (! is_string($caller[$field] ?? null) || $caller[$field] === '' || strlen($caller[$field]) > 255
                || preg_match('/[\\x00-\\x20\\x7f]/', $caller[$field])) {
                return $this->hold('worker_caller_unqualified');
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $field) {
            if (! $this->sha256($caller[$field] ?? null)) {
                return $this->hold('worker_caller_unqualified');
            }
        }
        if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $workKey)
            || $taskId === '' || $attempt < 0 || $generation < 1) {
            return $this->hold('admission_selector_invalid');
        }
        try {
            return DB::transaction(function () use ($workKey, $taskId, $attempt, $generation, $caller): array {
                $validated = $this->validateLocked($workKey, $caller['session_id'], $taskId, $attempt, $generation, preview: true);
                if (($validated['status'] ?? null) === 'hold') {
                    return $validated;
                }
                // The server-owned grant pins this exact initiating action. The
                // existing authenticated worker already checked its raw argument hash.
                $expected = $validated['grant']['worker_preview_caller'] ?? null;
                if (! is_array($expected) || $this->digest($expected) !== $this->digest($caller)) {
                    return $this->hold('worker_caller_binding_changed');
                }

                return ['schema' => 'plos.upstream_worker_preview.v1', 'status' => 'binding_verified',
                    'binding_verified' => true, 'admission_policy_enabled' => $validated['policy_enabled'],
                    'reason_code' => 'execution_not_qualified', 'execution_authorized' => false,
                    'operator_actionable' => false, 'pushover_allowed' => false, 'preparation_complete' => false];
            });
        } catch (\Throwable) {
            return $this->hold('admission_evidence_unavailable');
        }
    }

    /** Shared fresh locked bindings; only begin publishes after this succeeds. */
    private function validateLocked(string $workKey, string $sessionId, string $taskId, int $attempt, int $generation, bool $preview = false): array
    {
        $workRow = $this->row($workKey)->lockForUpdate()->first();
        if ($workRow === null) {
            return $this->hold('work_record_unavailable');
        }
        // Lock the original row first: serializes every attempt for this work.
        $receiptKey = $this->receiptKey($workKey);
        if ($this->row($receiptKey)->exists()) {
            return $this->hold('dispatch_replay_held');
        }
        $policyRow = $this->row(self::POLICY_KEY)->lockForUpdate()->first();
        $policy = $policyRow ? $this->decode($policyRow->config_value) : [];
        if (($policy['schema'] ?? null) !== self::POLICY_SCHEMA || (! is_bool($policy['enabled'] ?? null) || (! $preview && ! $policy['enabled']))) {
            return $this->hold('admission_policy_disabled_or_unavailable');
        }
        $grantRow = $this->row('upstream_source_admission_'.substr($workKey, -64))->lockForUpdate()->first();
        $grant = $grantRow ? $this->decode($grantRow->config_value) : [];
        if (! $this->validGrant($grant, $policy)
            || $grant['work_key'] !== $workKey || $grant['owner_session'] !== $sessionId
            || $grant['task_id'] !== $taskId || $grant['attempt'] !== $attempt
            || $grant['assignment_generation'] !== $generation) {
            return $this->hold('trusted_binding_mismatch');
        }
        $work = $this->decode($workRow->config_value);
        if (($work['schema'] ?? null) !== 'plos.upstream_source_preparation.v1'
            || ($work['status'] ?? null) !== 'preparation_pending'
            || ($work['owner'] ?? null) !== $grant['work_owner']
            || ($work['owning_record'] ?? null) !== $grant['owning_record']
            || ($work['operator_actionable'] ?? null) !== false || ($work['pushover_allowed'] ?? null) !== false
            || ! hash_equals($grant['work_sha256'], hash('sha256', $workRow->config_value))) {
            return $this->hold('work_record_changed_or_unowned');
        }
        if (! $this->sourceMatches($work, $workKey, $grant)) {
            return $this->hold('source_evidence_changed');
        }
        $task = DB::table('distributed_tasks')->where('task_id', $taskId)->lockForUpdate()->first();
        $agent = DB::table('distributed_agents')->where('agent_id', $grant['agent_id'])->lockForUpdate()->first();
        if ($task === null || $agent === null || $task->task_type !== self::TASK_TYPE
            || $task->status !== DistributedAgentService::TASK_RUNNING
            || (int) $task->assigned_agent_id !== (int) $agent->id
            || (int) $task->retry_count !== $attempt || (int) $task->assignment_generation !== $generation
            || $agent->node_name !== $grant['node_name']
            || ! in_array($agent->status, [DistributedAgentService::STATUS_ONLINE, DistributedAgentService::STATUS_BUSY], true)) {
            return $this->hold('assignment_owner_changed');
        }
        $bindingHash = $this->digest($grant);
        $payload = $this->decode($task->payload);
        $expected = ['work_key' => $workKey, 'work_sha256' => $grant['work_sha256'],
            'owner_session' => $sessionId, 'admission_sha256' => $bindingHash];
        if ($this->digest($payload['upstream_preparation'] ?? []) !== $this->digest($expected)) {
            return $this->hold('assignment_payload_mismatch');
        }
        // Existing custody API intentionally retains expired/unknown owners.
        $custody = $this->custody->findUnreleased(TaskCustodyService::SURFACE_AGENT_SESSION, $sessionId);
        if ($custody === null || (int) $custody->id !== $grant['session_custody_id']
            || $custody->owner_token !== $grant['session_owner_token'] || (int) $custody->is_expired !== 0) {
            return $this->hold('session_custody_unqualified');
        }
        $lockedCustody = DB::table('task_custody_records')->select('*')
            ->selectRaw('(expires_at > CURRENT_TIMESTAMP) AS lease_current')
            ->where('id', $custody->id)->lockForUpdate()->first();
        if ($lockedCustody === null || $lockedCustody->surface !== TaskCustodyService::SURFACE_AGENT_SESSION
            || $lockedCustody->surface_ref !== $sessionId || $lockedCustody->owner_token !== $grant['session_owner_token']
            || $lockedCustody->released_at !== null || (int) $lockedCustody->lease_current !== 1) {
            return $this->hold('session_custody_unqualified');
        }
        $workspace = $this->repoDev->qualifyWorkspace($grant['workspace']);
        if (! $this->workspaceMatches($workspace, $grant['workspace'])) {
            return $this->hold('repo_workspace_unqualified');
        }
        // Different work grants must not share an uncertain physical target.
        // This unique key also holds orphan reservations after a lost ACK.
        $workspaceKey = 'upstream_workspace_reservation_'.hash('sha256', $workspace['proof']['worktree_root']);
        if ($this->row($workspaceKey)->lockForUpdate()->first() !== null) {
            return $this->hold('workspace_dispatch_held');
        }

        return ['grant' => $grant, 'workspace' => $workspace, 'workspace_key' => $workspaceKey,
            'receipt_key' => $receiptKey, 'binding_hash' => $bindingHash, 'policy_enabled' => $policy['enabled']];
    }

    /** No release/reset. Qualified terminal reconciliation belongs to the later consumer. */
    public function markUnknown(string $workKey, string $sessionId, string $expectedReceiptHash): array
    {
        if (! preg_match('/^upstream_source_work_[a-f0-9]{64}$/D', $workKey) || ! $this->sha256($expectedReceiptHash)) {
            return $this->hold('admission_selector_invalid');
        }
        try {
            return DB::transaction(function () use ($workKey, $sessionId, $expectedReceiptHash): array {
                $query = $this->row($this->receiptKey($workKey));
                $row = $query->lockForUpdate()->first();
                $receipt = $row ? $this->decode($row->config_value) : [];
                if ($row === null || ! hash_equals($expectedReceiptHash, hash('sha256', $row->config_value))
                    || ($receipt['schema'] ?? null) !== self::RECEIPT_SCHEMA || ($receipt['work_key'] ?? null) !== $workKey
                    || ($receipt['owner_session'] ?? null) !== $sessionId || ($receipt['outcome'] ?? null) !== 'unknown') {
                    return $this->hold('attempt_owner_or_receipt_changed');
                }
                $receipt['phase'] = 'unknown_hold';
                $receipt['last_reviewed_at'] = now()->toIso8601String();
                $changed = $query->where('id', $row->id)->where('config_value', $row->config_value)
                    ->update(['config_value' => json_encode($receipt, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                return $this->hold($changed === 1 ? 'dispatch_outcome_unknown' : 'attempt_owner_or_receipt_changed');
            });
        } catch (\Throwable) {
            return $this->hold('admission_evidence_unavailable');
        }
    }

    private function sourceMatches(array $work, string $workKey, array $grant): bool
    {
        $source = $work['source'] ?? null;
        $fields = ['registry', 'key', 'url', 'identifier_scheme', 'identifier', 'recorded_revision'];
        if (! is_array($source) || count($source) !== count($fields)) {
            return false;
        }
        $identity = [];
        foreach ($fields as $field) {
            if (! is_string($source[$field] ?? null)) {
                return false;
            }
            $identity[$field] = $source[$field];
        }
        $sourceId = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $checkKey = 'upstream_source_check_'.$sourceId;
        $checkRow = $this->row($checkKey)->lockForUpdate()->first();
        $check = $checkRow ? $this->decode($checkRow->config_value) : [];
        $revision = $work['observed_version'] ?? null;

        return is_string($revision) && $revision !== '' && $grant['observed_version'] === $revision
            && 'upstream_source_work_'.hash('sha256', $sourceId.'|'.$revision.'|'.($work['observed_updated_at'] ?? '')) === $workKey
            && ($work['check_key'] ?? null) === 'ops.'.$checkKey
            && ($check['schema'] ?? null) === 'plos.upstream_source_check.v1'
            && ($check['revision_conclusive'] ?? null) === true
            && ($check['result']['state'] ?? null) === 'drift'
            && ($check['result']['observed_version'] ?? null) === $revision
            && ($check['result']['observed_updated_at'] ?? null) === ($work['observed_updated_at'] ?? null)
            && $this->digest($check['source'] ?? []) === $this->digest($identity)
            && hash_equals($grant['source_sha256'], $this->digest($identity))
            && $checkRow !== null && hash_equals($grant['check_sha256'], hash('sha256', $checkRow->config_value));
    }

    private function validGrant(array $grant, array $policy): bool
    {
        if (($grant['schema'] ?? null) !== self::GRANT_SCHEMA || ! is_array($grant['workspace'] ?? null)
            || ! is_array($grant['budgets'] ?? null) || ! is_array($policy['budget_caps'] ?? null)
            || ($grant['workspace']['node_name'] ?? null) !== ($grant['node_name'] ?? null)) {
            return false;
        }
        foreach (['work_key', 'work_owner', 'owner_session', 'task_id', 'agent_id', 'node_name', 'owning_record', 'session_owner_token', 'observed_version'] as $key) {
            if (! is_string($grant[$key] ?? null) || $grant[$key] === '') {
                return false;
            }
        }
        foreach (['work_sha256', 'source_sha256', 'check_sha256'] as $key) {
            if (! $this->sha256($grant[$key] ?? null)) {
                return false;
            }
        }
        foreach (['attempt' => 0, 'assignment_generation' => 1, 'session_custody_id' => 1] as $key => $minimum) {
            if (! is_int($grant[$key] ?? null) || $grant[$key] < $minimum) {
                return false;
            }
        }
        foreach (['wall_seconds', 'input_tokens', 'output_tokens', 'changed_files', 'artifact_bytes'] as $key) {
            if (! is_int($grant['budgets'][$key] ?? null) || $grant['budgets'][$key] < 1
                || ! is_int($policy['budget_caps'][$key] ?? null) || $policy['budget_caps'][$key] < 1
                || $grant['budgets'][$key] > $policy['budget_caps'][$key]) {
                return false;
            }
        }

        return true;
    }

    private function workspaceMatches(array $workspace, array $binding): bool
    {
        $proof = $workspace['proof'] ?? null;
        if (($workspace['schema'] ?? null) !== 'plos.repo_dev_workspace_proof.v1'
            || ($workspace['qualified'] ?? null) !== true || ! is_array($proof)
            || ($proof['stable'] ?? null) !== true || ! $this->sha256($proof['metadata_sha256'] ?? null)
            || ($proof['binding_sha256'] ?? null) !== hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR))) {
            return false;
        }
        foreach (['worktree_root', 'node_name', 'owner_uid', 'base_commit', 'worktree_ref', 'repo_dev_source_sha256'] as $field) {
            if (! array_key_exists($field, $binding) || ($proof[$field] ?? null) !== $binding[$field]) {
                return false;
            }
        }

        return true;
    }

    private function receiptKey(string $workKey): string
    {
        return 'upstream_source_attempt_'.substr($workKey, -64);
    }

    private function row(string $key): Builder
    {
        return DB::table('system_configs')->where('section', 'ops')->where('config_key', $key);
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Malformed upstream admission evidence.');
        }

        return $decoded;
    }

    private function digest(array $value): string
    {
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

    private function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function hold(string $reason): array
    {
        return ['status' => 'hold', 'reason_code' => $reason, 'execution_authorized' => false,
            'operator_actionable' => false, 'pushover_allowed' => false, 'preparation_complete' => false];
    }
}
