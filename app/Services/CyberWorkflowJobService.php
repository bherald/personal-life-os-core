<?php

namespace App\Services;

use App\Jobs\ExecuteCyberWorkflow;
use App\Jobs\ResumeCyberWorkflow;
use App\Services\UnifiedCli\CliQueuedFrameworkAction;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Private domain candidate. Its owner is injected from authenticated server context, never tool arguments. */
final class CyberWorkflowJobService
{
    public function __construct(private readonly CliStateStore $store, private readonly string $owner, private readonly ?CliQueuedFrameworkAction $nativeBridge = null)
    {
        if ($owner === '' || strlen($owner) > 191) {
            throw new RuntimeException('An authenticated task owner is required.');
        }
    }

    public function submit(string $submissionId, string $executionIntent): array
    {
        if ($executionIntent !== 'submit_live' || ! Str::isUuid($submissionId)) {
            throw new RuntimeException('An exact submission identity and explicit submit_live intent are required.');
        }
        $context = CliWorkflowExecutionScope::current();
        if ($context === null) {
            throw new RuntimeException('Durable work requires the existing captured CLI execution scope.');
        }
        $binding = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
        $artifacts = new CyberSuiteArtifactService;
        $artifact = $artifacts->current();
        $workflowHash = $artifacts->workflowFingerprint();
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        if (! is_string($policy['workflow_sha256'] ?? null) || ! hash_equals($policy['workflow_sha256'], $workflowHash)) {
            throw new RuntimeException('Current workflow differs from the qualified profile fingerprint.');
        }
        $workflowId = (int) DB::table('workflows')->where('name', $artifacts->workflowName())->value('id');
        $expectedNodes = DB::table('workflow_nodes')->where('workflow_id', $workflowId)->orderBy('node_order')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $existing = DB::table('cyber_workflow_jobs')->where('handle', $submissionId)->first();
        if ($existing !== null) {
            $this->owned($submissionId);
            if ($existing->scope_sha256 !== $binding || $existing->workflow_sha256 !== $workflowHash
                || json_decode($existing->artifact_binding, true, flags: JSON_THROW_ON_ERROR) !== $artifact) {
                throw new RuntimeException('Submission identity is bound to different execution settings.');
            }

            return $this->status($submissionId);
        }
        if (array_key_exists('native_tool', $artifact)) {
            (new CyberNativeToolSuiteService)->assertSubmission($artifact, $this->owner, $submissionId, $binding);
        }
        $session = $this->store->createSession(['turns' => [['id' => $submissionId, 'status' => 'inProgress']], 'messages' => []]);
        $inserted = DB::table('cyber_workflow_jobs')->insertOrIgnore([
            'handle' => $submissionId, 'owner' => $this->owner, 'session_id' => $session['id'],
            'scope_sha256' => $binding, 'scope_json' => json_encode($context, JSON_THROW_ON_ERROR),
            'dispatch_state' => 'submitting', 'created_at' => now(),
            'artifact_binding' => json_encode($artifact, JSON_THROW_ON_ERROR), 'workflow_sha256' => $workflowHash,
            'expected_nodes' => json_encode($expectedNodes, JSON_THROW_ON_ERROR),
            'expected_actions' => json_encode($artifacts->expectedActions(), JSON_THROW_ON_ERROR),
        ]);
        if ($inserted !== 1) {
            return $this->submit($submissionId, $executionIntent);
        }
        // Claim before dispatch. An ambiguous dispatch remains held, never auto-resubmitted.
        $this->store->startAction($session['id'], $submissionId,
            ['id' => 'job-submit', 'tool' => 'cyber_task_submit', 'arguments' => ['submission_id' => $submissionId]],
            ['instance_id' => 'workflow', 'model' => 'selected-by-captured-scope']);
        $input = ['task_id' => $submissionId, 'session_id' => $session['id'], 'turn_id' => $submissionId];
        ExecuteCyberWorkflow::dispatch($artifacts->workflowName(), (int) DB::table('workflows')->where('name', $artifacts->workflowName())->value('id'), $input, $session['id'], hash('sha256', 'cyber:'.$submissionId));
        DB::table('cyber_workflow_jobs')->where('handle', $submissionId)->where('dispatch_state', 'submitting')->update(['dispatch_state' => 'queued']);
        $this->store->completeAction($session['id'], $submissionId, 'job-submit', ['success' => true, 'output' => 'Durable job accepted: '.$submissionId]);

        return $this->status($submissionId);
    }

    public function status(string $handle): array
    {
        $row = $this->owned($handle);
        $session = $this->store->session($row->session_id);
        $receipts = $this->store->actionReceipts($row->session_id, $handle);
        $events = DB::table('workflow_events')->where('metadata->trace_session_id', $row->session_id)->orderBy('id')->get();
        $progress = [];
        foreach ($events as $event) {
            $progress[] = ['event_id' => $event->id, 'execution_id' => $event->execution_id, 'type' => $event->event_type, 'node_id' => $event->node_id];
        }
        $unknown = array_keys(array_filter($receipts, fn ($receipt) => $receipt['state'] === 'started'));
        $expected = json_decode($row->expected_actions, true, flags: JSON_THROW_ON_ERROR);
        $complete = count(array_filter($expected, fn ($id) => isset($receipts[$id]) && $receipts[$id]['state'] === 'completed' && $receipts[$id]['document']['result']['success'] === true));
        $runIds = [];
        foreach ($events as $event) {
            $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
            if (isset($metadata['run_id'])) {
                $runIds[] = (int) $metadata['run_id'];
            }
        }
        $expectedNodes = json_decode($row->expected_nodes, true, flags: JSON_THROW_ON_ERROR);
        $completedNodes = array_column(array_filter($progress, fn ($event) => $event['type'] === 'NodeCompleted'), 'node_id');
        $allNodesComplete = array_diff($expectedNodes, $completedNodes) === [];
        $finalRunState = $runIds === [] ? null : DB::table('workflow_runs')->where('id', $runIds[array_key_last($runIds)])->value('status');
        $cancel = ($session['turns'][0]['cancel_requested'] ?? false) === true;
        $failed = $progress !== [] && $progress[array_key_last($progress)]['type'] === 'NodeFailed';
        // A request is cooperative: an admitted probe can still finish. Only terminal worker evidence confirms stopping.
        $workerStopped = in_array($finalRunState, ['completed', 'failed'], true)
            || in_array($row->dispatch_state, ['failed_before_or_during_workflow', 'capacity_cancelled', 'capacity_expired'], true);
        $cancellationState = $workerStopped ? 'cancelled' : 'cancellation_requested';
        $state = $complete === count($expected) && $finalRunState === 'completed' && $allNodesComplete ? 'completed' : ($unknown !== [] ? 'running_or_outcome_unknown' : ($cancel ? $cancellationState : ($failed ? 'failed' : ($progress !== [] ? 'running' : $row->dispatch_state))));

        $status = ['handle' => $handle, 'state' => $state, 'completed_steps' => $complete, 'total_steps' => count($expected), 'workflow_state' => $finalRunState,
            'artifact_sha256' => json_decode($row->artifact_binding, true, flags: JSON_THROW_ON_ERROR)['archive_sha256'],
            'findings_status' => 'operator_review_required',
            'cancel_requested' => $cancel, 'unresolved_action_ids' => $unknown,
            'progress' => $progress, 'scope_sha256' => $row->scope_sha256,
            'automatic_replay_allowed' => false];
        if ($expected === ['managed-model-suite']) {
            $status['managed_progress'] = array_values(array_filter($this->store->events($row->session_id), fn ($event) => $event['type'] === 'cyber_managed_model_progress'));
            $status['capacity_wait'] = isset($row->capacity_wait_state) ? json_decode($row->capacity_wait_state, true, flags: JSON_THROW_ON_ERROR) : null;
        }

        if ($expected === ['native-framework-action']) {
            $status['native_progress'] = array_values(array_filter($this->store->events($row->session_id), fn ($event) => $event['type'] === 'cyber_native_tool_progress'));
            $status['remote_result_verified'] = $unknown === [] && $complete === count($expected);
            $status['native_control'] = $this->latestNativeControl($row);
            $status['process_tree_termination_verified'] = ($status['native_control']['process_tree_termination_verified'] ?? false) === true;
            if ($cancel && $state !== 'completed' && ! $status['process_tree_termination_verified']) {
                $status['state'] = $unknown !== [] ? 'running_or_outcome_unknown' : 'cancellation_requested';
            }

        }

        return $status;
    }

    public function cancel(string $handle, string $executionIntent): array
    {
        if ($executionIntent !== 'cancel_live') {
            throw new RuntimeException('Explicit cancel_live intent is required.');
        }
        $row = $this->owned($handle);
        if ($this->status($handle)['state'] === 'completed') {
            throw new RuntimeException('Completed task cannot be cancelled.');
        }
        $this->store->requestTurnCancellation($row->session_id, $handle);
        if (json_decode($row->expected_actions, true, flags: JSON_THROW_ON_ERROR) === ['native-framework-action']
            && isset($this->store->actionReceipts($row->session_id, $handle)['native-framework-action'])) {
            $this->nativeControl($row, 'cancel', false);
        }

        return $this->status($handle);
    }

    /** Explicit remote readback; ordinary status never contacts the worker. */
    public function refresh(string $handle, string $executionIntent): array
    {
        if ($executionIntent !== 'refresh_live') {
            throw new RuntimeException('Explicit refresh_live intent is required.');
        }
        $this->nativeControl($this->owned($handle), 'status', false);

        return $this->status($handle);
    }

    /** Reconcile the original parent receipt only; never resume this workflow. */
    public function reconcile(string $handle, string $executionIntent): array
    {
        if ($executionIntent !== 'reconcile_live') {
            throw new RuntimeException('Explicit reconcile_live intent is required.');
        }
        $this->nativeControl($this->owned($handle), 'status', true);

        return $this->status($handle);
    }

    private function nativeControl(object $row, string $operation, bool $reconcile): void
    {
        $binding = json_decode($row->artifact_binding, true, flags: JSON_THROW_ON_ERROR);
        $plan = $binding['native_tool'] ?? null;
        if (json_decode($row->expected_actions, true, flags: JSON_THROW_ON_ERROR) !== ['native-framework-action'] || ! is_array($plan)) {
            throw new RuntimeException('Explicit remote control is available only for the original native job.');
        }
        $job = ['owner' => $row->owner, 'handle' => $row->handle, 'scope_sha256' => $row->scope_sha256];
        $identity = $job + ['plan_sha256' => CliQueuedFrameworkAction::digest($plan), 'operation' => $reconcile ? 'reconcile' : $operation];
        // Persist intent before contacting the exact original worker. No current
        // registry/profile lookup can substitute a new plan after revocation.
        $this->store->recordEvent($row->session_id, 'cyber_native_control_requested', $identity);
        try {
            $receipt = ($this->nativeBridge ?? new CliQueuedFrameworkAction)->control($plan, $job, $operation, $reconcile);
            $this->store->recordEvent($row->session_id, 'cyber_native_control_result', $identity + ['receipt' => $receipt]);
        } catch (\Throwable) {
            $this->store->recordEvent($row->session_id, 'cyber_native_control_unknown', $identity + [
                'automatic_replay_allowed' => false, 'error' => 'Original remote control was not verified. Retain the handle and custody; no automatic retry.',
            ]);
        }
    }

    private function latestNativeControl(object $row): ?array
    {
        $plan = json_decode($row->artifact_binding, true, flags: JSON_THROW_ON_ERROR)['native_tool'] ?? null;
        if (! is_array($plan)) {
            return null;
        }
        $last = null;
        foreach ($this->store->events($row->session_id) as $event) {
            $data = $event['document']['event'] ?? [];
            if (! in_array($event['type'], ['cyber_native_control_result', 'cyber_native_control_unknown'], true)
                || ($data['owner'] ?? null) !== $row->owner || ($data['handle'] ?? null) !== $row->handle
                || ($data['scope_sha256'] ?? null) !== $row->scope_sha256
                || ($data['plan_sha256'] ?? null) !== CliQueuedFrameworkAction::digest($plan)) {
                continue;
            }
            $receipt = $data['receipt'] ?? [];
            $last = ['operation' => $data['operation'], 'state' => $receipt['state'] ?? 'control_outcome_unknown',
                'cancel_requested' => $receipt['cancel_requested'] ?? null,
                'process_tree_termination_verified' => $receipt['process_tree_termination_verified'] ?? false,
                'parent_reconciled' => $receipt['parent_reconciled'] ?? false,
                'native_terminal_sha256' => $receipt['native_terminal_sha256'] ?? null,
                'deadline_ms' => $receipt['native_terminal']['deadline_ms'] ?? null,
                'timed_out' => $receipt['native_terminal']['timed_out'] ?? null,
                'workflow_resumed' => false, 'operator_review_required' => true, 'automatic_replay_allowed' => false];
        }

        return $last;
    }

    public function resume(string $handle, string $executionIntent): array
    {
        if ($executionIntent !== 'resume_live') {
            throw new RuntimeException('Explicit resume_live intent is required.');
        }
        $row = $this->owned($handle);
        if (json_decode($row->expected_actions, true, flags: JSON_THROW_ON_ERROR) === ['native-framework-action']) {
            throw new RuntimeException('Native framework jobs cannot resume or replay. Reconnect to the original handle and inspect remote custody.');
        }
        if (json_decode($row->expected_actions, true, flags: JSON_THROW_ON_ERROR) === ['managed-model-suite']) {
            throw new RuntimeException('Managed model jobs cannot resume or replay. Review the retained outcome and exact lease before approving a new job.');
        }
        $status = $this->status($handle);
        if ($status['unresolved_action_ids'] !== [] || $status['cancel_requested'] || $status['state'] !== 'failed') {
            throw new RuntimeException('Task is not safely resumable; resolve unknown outcomes and retain operator cancellation.');
        }
        $context = CliWorkflowExecutionScope::current();
        if ($context === null || hash('sha256', json_encode($context, JSON_THROW_ON_ERROR)) !== $row->scope_sha256) {
            throw new RuntimeException('Resume must retain the exact approved execution settings.');
        }
        $executionId = $status['progress'][array_key_last($status['progress'])]['execution_id'];
        // Existing engine enforces a unique checkpoint claim; the private queued adapter only invokes it.
        (new CyberSuiteArtifactService)->assertJobBinding($row);
        ResumeCyberWorkflow::dispatch($handle, $executionId);

        return $this->status($handle) + ['resume_queued' => true];
    }

    private function owned(string $handle): object
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $handle)->where('owner', $this->owner)->first();
        if ($row === null) {
            throw new RuntimeException('Task not found for this authenticated owner.');
        }

        return $row;
    }
}
