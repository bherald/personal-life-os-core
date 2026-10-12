<?php

namespace App\Services;

use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliStateStore;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Pre-workflow capacity waiting within the existing durable job; never replays work. */
class CyberModelCapacityWaitService
{
    public const CONFIG_KEY = 'model_host_controller_capacity_wait_policy';

    public function __construct(private readonly ?Closure $planner = null) {}

    public function policy(): ?array
    {
        $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', self::CONFIG_KEY)->first();
        if ($row === null) {
            return null;
        }
        $p = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($p) || array_diff(array_keys($p), ['schema', 'revision', 'enabled', 'maximum_wait_seconds', 'maximum_checks', 'retry_delays_seconds']) !== []
            || ($p['schema'] ?? null) !== 'plos.model_host_controller.capacity_wait.v1'
            || ! is_bool($p['enabled'] ?? null) || ! is_string($p['revision'] ?? null) || $p['revision'] === ''
            || ! is_int($p['maximum_wait_seconds'] ?? null) || $p['maximum_wait_seconds'] < 1
            || ! is_int($p['maximum_checks'] ?? null) || $p['maximum_checks'] < 1
            || ! is_array($p['retry_delays_seconds'] ?? null) || ! array_is_list($p['retry_delays_seconds']) || $p['retry_delays_seconds'] === []) {
            throw new RuntimeException('Capacity wait policy is invalid.');
        }
        foreach ($p['retry_delays_seconds'] as $delay) {
            if (! is_int($delay) || $delay < 1 || $delay > $p['maximum_wait_seconds']) {
                throw new RuntimeException('Capacity retry delays must be positive table-owned seconds within the wait budget.');
            }
        }

        return $p;
    }

    /** Called before WorkflowEngine, action claims, activation or inference. */
    public function prepare(object $row, array $binding, int $queueDeadline): array
    {
        if (! isset($binding['managed_model'])) {
            return ['action' => 'proceed'];
        }
        $policy = $this->policy();
        $raw = $row->capacity_wait_state ?? null;
        $state = $raw === null ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if ($state !== null && (! is_array($state) || ! is_int($state['deadline'] ?? null)
            || ! is_int($state['checks'] ?? null) || $state['checks'] < 0
            || ! is_string($state['phase'] ?? null)
            || (isset($state['next_check_at']) && ! is_int($state['next_check_at'])))) {
            throw new RuntimeException('Durable capacity wait state is invalid.');
        }
        if ($policy === null || ! $policy['enabled']) {
            return $state === null ? ['action' => 'proceed'] : $this->finish($row, $raw, $state, 'held');
        }
        if ($state !== null && ! in_array($state['phase'] ?? null, ['waiting'], true)) {
            return ['action' => 'hold'];
        }
        $settings = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        CliLocalBindings::readPrivateFile($settings['state_path']);
        $store = new CliStateStore($settings['state_path']);
        // No prior effect, workflow execution or ambiguous action can enter the wait path.
        if (! $this->workNotStarted($store, $row)) {
            return ['action' => 'hold'];
        }
        $now = now()->getTimestamp();
        $created = strtotime($row->created_at);
        if ($created === false || $created > $now) {
            throw new RuntimeException('Capacity wait requires a valid original submission time.');
        }
        $deadline = min($queueDeadline, $created + $policy['maximum_wait_seconds'], $state['deadline'] ?? PHP_INT_MAX);
        $next = ['phase' => 'checking', 'deadline' => $deadline, 'checks' => $state['checks'] ?? 0,
            'next_check_at' => null, 'requirements' => [], 'policy_revision' => $policy['revision']];
        if ($this->cancelled($store, $row)) {
            return $this->finish($row, $raw, $next, 'cancelled');
        }
        if ($now >= $deadline || $next['checks'] >= $policy['maximum_checks']) {
            return $this->finish($row, $raw, $next, 'expired');
        }
        if (($state['next_check_at'] ?? 0) > $now) {
            return ['action' => 'wait', 'delay_seconds' => min($state['next_check_at'], $deadline) - $now];
        }
        $claim = json_encode($next, JSON_THROW_ON_ERROR);
        if (! $this->transition($row, $raw, $claim, 'capacity_checking')) {
            return ['action' => 'hold'];
        }
        try {
            $observation = $this->planner !== null ? ($this->planner)($row, $binding)
                : app(CyberManagedModelSuiteService::class)->capacityPlan($row, $binding);
            $plan = $observation['plan'];
            $shortage = ModelHostControllerResourcePolicyService::temporaryShortage($plan,
                $observation['target'], $observation['model'], $observation['engine']);
            $next['checks']++;
            if ($this->policy() !== $policy) {
                return $this->finish($row, $claim, $next, 'held');
            }
            if (! $this->workNotStarted($store, $row)) {
                return $this->finish($row, $claim, $next, 'held');
            }
            if ($this->cancelled($store, $row)) {
                return $this->finish($row, $claim, $next, 'cancelled');
            }
            $now = now()->getTimestamp();
            if ($now >= $deadline) {
                return $this->finish($row, $claim, $next, 'expired');
            }
            if ($shortage !== null) {
                $next['requirements'] = $shortage;
                if ($next['checks'] >= $policy['maximum_checks']) {
                    return $this->finish($row, $claim, $next, 'expired');
                }
                $delays = $policy['retry_delays_seconds'];
                $delay = min($delays[min($next['checks'] - 1, count($delays) - 1)], $deadline - $now);
                $next['phase'] = 'waiting';
                $next['next_check_at'] = $now + $delay;
                if (! $this->transition($row, $claim, json_encode($next, JSON_THROW_ON_ERROR), 'capacity_waiting')) {
                    return ['action' => 'hold'];
                }

                return ['action' => 'wait', 'delay_seconds' => $delay];
            }
            if (! ModelHostControllerResourcePolicyService::availableCapacity($plan,
                $observation['target'], $observation['model'], $observation['engine'])) {
                return $this->finish($row, $claim, $next, 'held');
            }
            // Persist dispatch intent before crossing the workflow boundary. A lost worker is held.
            $next['phase'] = 'dispatched';
            $accepted = $this->transition($row, $claim, json_encode($next, JSON_THROW_ON_ERROR), 'capacity_admitted');

            return ['action' => $accepted ? 'proceed' : 'hold'];
        } catch (\Throwable) {
            // Transport, binding and malformed replies are not capacity refusals.
            return $this->finish($row, $claim, $next, 'held');
        }
    }

    private function cancelled(CliStateStore $store, object $row): bool
    {
        $session = $store->session($row->session_id);
        $turns = array_values(array_filter($session['turns'] ?? [], fn ($turn) => ($turn['id'] ?? null) === $row->handle));
        if (count($turns) !== 1 || ($turns[0]['status'] ?? null) !== 'inProgress') {
            throw new RuntimeException('Original capacity-wait turn is unavailable.');
        }

        return ($turns[0]['cancel_requested'] ?? false) === true;
    }

    private function workNotStarted(CliStateStore $store, object $row): bool
    {
        $receipts = $store->actionReceipts($row->session_id, $row->handle);

        return array_diff(array_keys($receipts), ['job-submit']) === []
            && ! DB::table('workflow_events')->where('metadata->trace_session_id', $row->session_id)->exists();
    }

    private function finish(object $row, ?string $previous, array $state, string $phase): array
    {
        $state['phase'] = $phase;
        $state['next_check_at'] = null;
        $this->transition($row, $previous, json_encode($state, JSON_THROW_ON_ERROR), 'capacity_'.$phase);

        return ['action' => 'hold'];
    }

    private function transition(object $row, ?string $previous, string $next, string $dispatch): bool
    {
        $query = DB::table('cyber_workflow_jobs')->where('handle', $row->handle)->where('owner', $row->owner)
            ->where('scope_sha256', $row->scope_sha256)->where('workflow_sha256', $row->workflow_sha256)
            ->whereIn('dispatch_state', ['submitting', 'queued', 'capacity_waiting', 'capacity_checking']);
        $previous === null ? $query->whereNull('capacity_wait_state') : $query->where('capacity_wait_state', $previous);

        return $query->update(['capacity_wait_state' => $next, 'dispatch_state' => $dispatch]) === 1;
    }
}
