<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Throwable;

/** Reopen original operator authority for a queued action. Never creates or transfers approval. */
final class CliQueuedFrameworkAction
{
    public function __construct(private readonly ?Closure $processFactory = null) {}

    public function qualify(array $plan, array $job): array
    {
        $keys = ['schema', 'owner', 'handle', 'scope_sha256', 'state_path', 'session_id', 'turn_id', 'action', 'approval_scope',
            'generation', 'configuration_sha256', 'framework_context', 'worker_id', 'worker_address', 'parent_root', 'node', 'node_sha256', 'parent_files', 'implementation_sha256'];
        if (($plan['schema'] ?? null) !== 'plos.cli.queued-framework-action.v1' || array_diff(array_keys($plan), $keys) !== []) {
            throw new RuntimeException('Unsupported server-owned queued framework binding.');
        }
        foreach (['owner', 'handle', 'scope_sha256'] as $key) {
            if (! is_string($plan[$key] ?? null) || $plan[$key] === '' || ($job[$key] ?? null) !== $plan[$key]) {
                throw new RuntimeException('Queued action differs from authenticated job ownership and scope.');
            }
        }
        if (! is_string($plan['state_path'] ?? null) || realpath($plan['state_path']) !== $plan['state_path']) {
            throw new RuntimeException('An existing server-owned private approval store is required.');
        }
        CliLocalBindings::assertPrivateSqlite($plan['state_path']);
        $store = new CliStateStore($plan['state_path']);
        $session = $store->session($plan['session_id']);
        $store->assertTurnNotCancelled($plan['session_id'], $plan['turn_id']);
        $snapshot = $store->snapshot($session['generation'] ?? '');
        if ($snapshot === null || ($session['generation'] ?? null) !== ($plan['generation'] ?? null)
            || self::digest($snapshot['snapshot']) !== $plan['generation']) {
            throw new RuntimeException('Queued action snapshot changed.');
        }
        $action = $plan['action'] ?? null;
        $pending = (new LosslessConversation($session['messages'] ?? []))->pendingToolCalls();
        $entry = $pending[$action['id'] ?? ''] ?? null;
        if (count($pending) !== 1 || ! is_array($action) || array_keys($action) !== ['id', 'name', 'arguments'] || $entry === null
            || ($session['message_turn_ids'][$entry['message_index']] ?? null) !== $plan['turn_id']
            || $entry['call']['function']['name'] !== $action['name']
            || json_decode($entry['call']['function']['arguments'], true, flags: JSON_THROW_ON_ERROR) !== $action['arguments']) {
            throw new RuntimeException('Original pending framework action changed.');
        }
        $configuration = new CliConfiguration($store);
        $values = $configuration->effective($session)['values'];
        $definition = (new CliToolCatalog($configuration))->definitions($session)[$action['name']] ?? null;
        if ($definition === null) {
            throw new RuntimeException('Qualified tool definition unavailable.');
        }
        $this->assertParentInstallation($plan);
        $framework = new CliFrameworkServices($store, $session['id'], $plan['parent_root'], $plan['node'],
            new CliArtifactStore(dirname($plan['state_path']).'/artifacts'), $this->processFactory);
        $context = $framework->context($action['name'], $definition);
        if ($context === null || $context !== ($plan['framework_context'] ?? null)
            || self::configurationHash($plan['generation'], $values, $context) !== ($plan['configuration_sha256'] ?? null)) {
            throw new RuntimeException('Queued framework selection or configuration changed.');
        }
        $target = $values['cli.framework_services']['targets'][$context['operation_target']] ?? [];
        $host = $target['host'] ?? '';
        $host = str_contains($host, '@') ? explode('@', $host, 2)[1] : $host;
        $address = $values['cli.host_addresses'][strtolower($host)] ?? $host;
        if (($target['transport'] ?? null) !== 'ssh' || ($target['platform'] ?? null) !== 'windows'
            || CliFrameworkWorkerHold::address($address) !== CliFrameworkWorkerHold::address($plan['worker_address'])) {
            throw new RuntimeException('Queued worker identity differs from the selected physical host.');
        }
        $scope = $plan['approval_scope'] ?? [];
        foreach (['configuration_sha256' => $plan['configuration_sha256'], 'instance_id' => $plan['worker_id'],
            'queued_binding_sha256' => self::authorityHash($plan), 'durable_owner' => $job['owner'], 'durable_handle' => $job['handle'], 'workflow_scope_sha256' => $job['scope_sha256']] as $key => $value) {
            if (($scope[$key] ?? null) !== $value) {
                throw new RuntimeException('Approval was not bound to this exact durable job before decision.');
            }
        }
        if (! $store->hasApproval($session['id'], $plan['turn_id'], $action, $scope)) {
            throw new RuntimeException('Original exact operator approval is absent, denied or expired.');
        }

        return compact('store', 'framework', 'context', 'definition', 'action', 'target');
    }

    public function execute(array $plan, array $job, Closure $assertJobActive, Closure $progress): array
    {
        $assertJobActive();
        $prepared = $this->qualify($plan, $job);
        $store = $prepared['store'];
        $old = $store->actionReceipts($plan['session_id'], $plan['turn_id'])[$plan['action']['id']] ?? null;
        if ($old !== null) {
            throw new RuntimeException('Original action already has a receipt; no queued replay.');
        }
        $binding = self::digest($plan);
        $hold = new CliFrameworkWorkerHold;
        $hold->claim($plan['worker_id'], $plan['worker_address'], $binding);
        try {
            $store->startAction($plan['session_id'], $plan['turn_id'], $plan['action'],
                ['instance_id' => $plan['worker_id'], 'model' => $plan['approval_scope']['model']]);
            $keeper = function () use ($plan, $job, $assertJobActive, $hold, $binding): void {
                $assertJobActive();
                $hold->assertOwned($plan['worker_id'], $binding);
                $this->qualify($plan, $job);
            };
            $keeper();
            $progress('dispatching');
            $context = $prepared['context'] + ['_cli_action_id' => $plan['action']['id'], '_cli_action_turn' => $plan['turn_id'], '_cli_approval_scope' => $plan['approval_scope']];
            $result = $prepared['framework']->call($plan['action']['name'], $plan['action']['arguments'], $context, $prepared['definition'], $keeper);
            if (! is_array($result) || ! is_bool($result['success'] ?? null)) {
                throw new RuntimeException('Complete typed framework result missing.');
            }
            $bytes = json_encode($result, JSON_THROW_ON_ERROR);
            $store->completeAction($plan['session_id'], $plan['turn_id'], $plan['action']['id'], ['success' => $result['success'], 'output' => $bytes]);
            // This bridge accepts one pending action only; close its original held turn with the verified tool reply.
            $store->assertTurnNotCancelled($plan['session_id'], $plan['turn_id']);
            $session = $store->session($plan['session_id']);
            $session['messages'][] = ['role' => 'tool', 'tool_call_id' => $plan['action']['id'], 'content' => $bytes];
            $session['message_turn_ids'][] = $plan['turn_id'];
            $session['turns'][array_key_last($session['turns'])]['status'] = 'completed';
            $store->saveSession($session);
            // Terminal progress must persist before custody becomes available to another action.
            $progress('remote_result_verified');
            $hold->complete($plan['worker_id'], $binding, hash('sha256', $bytes));

            return $result;
        } catch (Throwable $error) {
            $hold->unknown($plan['worker_id'], $binding);
            throw new CliToolOutcomeUnknown('Queued remote action has no fully verified terminal custody. Preserve its original identity; no automatic retry or reclaim.', previous: $error);
        }
    }

    /** Control/reconcile one already dispatched job; never reevaluate it into a new action. */
    public function control(array $plan, array $job, string $operation, bool $reconcile = false): array
    {
        if (! in_array($operation, ['status', 'cancel'], true) || ($reconcile && $operation !== 'status')
            || ($plan['schema'] ?? null) !== 'plos.cli.queued-framework-action.v1') {
            throw new RuntimeException('Bounded original-job control operation required.');
        }
        foreach (['owner', 'handle', 'scope_sha256'] as $key) {
            if (! is_string($plan[$key] ?? null) || $plan[$key] === '' || ($job[$key] ?? null) !== $plan[$key]) {
                throw new RuntimeException('Original job control differs from authenticated ownership and scope.');
            }
        }
        $binding = self::digest($plan);
        $hold = new CliFrameworkWorkerHold;
        $hold->assertOriginal($plan['worker_id'], $plan['worker_address'], $binding);
        CliLocalBindings::assertPrivateSqlite($plan['state_path']);
        $this->assertParentInstallation($plan);
        $store = new CliStateStore($plan['state_path']);
        $framework = new CliFrameworkServices($store, $plan['session_id'], $plan['parent_root'], $plan['node'],
            new CliArtifactStore(dirname($plan['state_path']).'/artifacts'), $this->processFactory);
        $readback = $framework->control($plan['turn_id'], $plan['action']['id'], $operation);
        if (! $reconcile) {
            return $readback + ['parent_reconciled' => false];
        }
        if ($readback['state'] !== 'result_recorded' || ! $readback['process_tree_termination_verified']) {
            throw new RuntimeException('Reconciliation requires both a durable result and verified native whole-job termination. Original custody remains held.');
        }
        $result = json_decode($readback['result_json'], true, flags: JSON_THROW_ON_ERROR) + ['framework_remote_receipt' => $readback];
        $bytes = json_encode($result, JSON_THROW_ON_ERROR);
        $receipt = $store->actionReceipts($plan['session_id'], $plan['turn_id'])[$plan['action']['id']] ?? null;
        if (($receipt['state'] ?? null) === 'started') {
            $store->completeAction($plan['session_id'], $plan['turn_id'], $plan['action']['id'], ['success' => $result['success'], 'output' => $bytes]);
        }
        $receipt = $store->actionReceipts($plan['session_id'], $plan['turn_id'])[$plan['action']['id']] ?? null;
        $storedBytes = $receipt['document']['result']['output'] ?? null;
        $storedResult = is_string($storedBytes) ? json_decode($storedBytes, true, flags: JSON_THROW_ON_ERROR) : null;
        $storedRemote = is_array($storedResult) ? ($storedResult['framework_remote_receipt'] ?? null) : null;
        // Cancellation intent can arrive after an immutable result was completed.
        // Retain the original parent bytes; only that later intent may differ.
        if (($receipt['state'] ?? null) !== 'completed' || ! is_array($storedRemote)
            || ($receipt['document']['result']['success'] ?? null) !== $result['success']
            || array_diff_key($storedResult, ['framework_remote_receipt' => true]) !== array_diff_key($result, ['framework_remote_receipt' => true])
            || ! is_bool($storedRemote['cancel_requested'] ?? null)
            || ($storedRemote['cancel_requested'] && ! $readback['cancel_requested'])
            || array_diff_key($storedRemote, ['cancel_requested' => true]) !== array_diff_key($readback, ['cancel_requested' => true])) {
            throw new RuntimeException('Original parent result changed; custody remains held.');
        }
        $bytes = $storedBytes;
        // Persist before releasing exclusion. Do not close the operator turn,
        // invent a model/tool message, resume the workflow or clear cancellation.
        $store->recordEvent($plan['session_id'], 'framework_remote_reconciled', ['action_id' => $plan['action']['id'],
            'binding_sha256' => $binding, 'result_sha256' => hash('sha256', $bytes), 'native_terminal_sha256' => $readback['native_terminal_sha256']]);
        $hold->reconcileVerified($plan['worker_id'], $plan['worker_address'], $binding, hash('sha256', $bytes), $readback);

        return $readback + ['parent_reconciled' => true, 'workflow_resumed' => false, 'operator_review_required' => true];
    }

    private function assertParentInstallation(array $plan): void
    {
        if (! is_string($plan['parent_root'] ?? null) || realpath($plan['parent_root']) !== $plan['parent_root']
            || ! is_string($plan['node'] ?? null) || realpath($plan['node']) !== $plan['node'] || ! is_array($plan['parent_files'] ?? null)) {
            throw new RuntimeException('Qualified parent framework installation unavailable.');
        }
        if (! is_string($plan['node_sha256'] ?? null) || ! hash_equals($plan['node_sha256'], hash_file('sha256', $plan['node']))) {
            throw new RuntimeException('Qualified Node executable changed.');
        }
        foreach ([self::class, CliFrameworkServices::class, CliFrameworkServicePolicy::class, CliFrameworkWorkerHold::class, CliStateStore::class, CliWindowsActionProcess::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if (! is_string($plan['implementation_sha256'][$class] ?? null) || hash_file('sha256', $file) !== $plan['implementation_sha256'][$class]) {
                throw new RuntimeException('Qualified shared framework implementation changed.');
            }
        }
        foreach (['scripts/plos-cli/internal-operation.mjs'] as $required) {
            if (! isset($plan['parent_files'][$required])) {
                throw new RuntimeException('Parent launcher source pin required.');
            }
        }
        foreach ($plan['parent_files'] as $relative => $hash) {
            $file = $plan['parent_root'].'/'.$relative;
            if (! is_string($relative) || str_contains($relative, '..') || realpath($file) !== $file || is_link($file)
                || ! is_file($file) || ! is_string($hash) || ! hash_equals($hash, hash_file('sha256', $file))) {
                throw new RuntimeException('Parent framework source changed.');
            }
        }
    }

    public static function authorityHash(array $plan): string
    {
        return self::digest(array_diff_key($plan, ['approval_scope' => true]));
    }

    public static function configurationHash(string $generation, array $values, array $context): string
    {
        return self::digest([$generation, $values, $context]);
    }

    public static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
