<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** Activate the selected table-admitted target using existing controller ownership. */
final class CliAgentActivation
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly Closure $qualification,
        private readonly Closure $controller,
    ) {}

    public function prepare(array $session, array $candidate): array
    {
        $mode = (new CliConfiguration($this->store))->effective($session)['values']['cli.engine_activation'] ?? 'auto';
        if (! in_array($mode, ['auto', 'manual'], true)) {
            throw new RuntimeException('Engine activation mode must be auto or manual.');
        }
        if ($mode === 'manual') {
            return ['allowed' => true, 'selection_committed' => false];
        }
        $proof = ($this->qualification)($candidate, $session);
        if (($proof['allowed'] ?? false) !== true) {
            return $proof;
        }
        if (($proof['contract']['runtime_management'] ?? null) === 'provider') {
            return ['allowed' => true, 'selection_committed' => false];
        }
        $target = $proof['contract']['controller_target'] ?? null;
        if (! is_string($target) || $target === '' || $target === 'kimi_k3_87') {
            throw new RuntimeException('Selected runtime has no automatic controller target; dedicated K3 gates remain required.');
        }
        $connection = ($this->controller)($session);
        if (! $connection instanceof CliModelHostStatus) {
            throw new RuntimeException('Selected controller binding is unavailable.');
        }
        $lease = $this->store->session($session['id'])['host_lease'] ?? null;
        if ($lease !== null && ! in_array($lease['phase'] ?? null, ['owned', 'released', 'not_started'], true)) {
            throw new RuntimeException('Existing lifecycle outcome is unresolved. Review /host lease; no automatic replay was attempted.');
        }
        if (($lease['phase'] ?? null) === 'owned') {
            if (($lease['controller_identity'] ?? null) !== $connection->identity()) {
                throw new RuntimeException('Existing lease belongs to another controller; no release or activation attempted.');
            }
            if (($lease['target'] ?? null) === $target && ($lease['model'] ?? null) === $candidate['model']) {
                if (strtotime($lease['expires_at'] ?? '') > time()) {
                    // Ordinary admission still checks remote ownership and runtime identity.
                    return ['allowed' => true, 'selection_committed' => true];
                }
                $status = $connection->status()['controller']['lifecycle'] ?? [];
                if (($status['lease_present'] ?? null) !== true
                    || ($status['lease_id_sha256'] ?? null) !== hash('sha256', $lease['lease_id'] ?? '')) {
                    throw new RuntimeException('Expired local lease has no verified remote owner; no recovery was attempted.');
                }
                if (($status['lease_expired'] ?? null) === false && ($status['active_target'] ?? null) === $target) {
                    // Refresh a stale local expiry without restarting the still-owned engine.
                    (new CliHostLifecycle($this->store, $session['id'], $connection))->heartbeat();

                    return ['allowed' => true, 'selection_committed' => true];
                }
                if (($status['lease_expired'] ?? null) !== true) {
                    throw new RuntimeException('Current lease expiry or target is unverified; no recovery was attempted.');
                }
                // A verified expired lease is released below, then the same target is activated.

            }
        }
        $policy = $connection->policy();
        $spec = $policy['targets'][$target] ?? null;
        if (! $policy['enabled'] || ! is_array($spec) || ! $spec['activation_allowed']
            || ! in_array($spec['state'], ['production', 'staged'], true)
            || $spec['model'] !== $candidate['model']
            || ($spec['engine'] !== $candidate['engine']
                && ! (CliAgentRuntime::isColibriEngine($spec['engine']) && CliAgentRuntime::isColibriEngine($candidate['engine']))
                && ! ($spec['engine'] === 'ollama' && ($candidate['instance']['instance_type'] ?? null) === 'ollama'
                    && ($proof['contract']['wire_api'] ?? null) === 'ollama_chat'))) {
            throw new RuntimeException('Current controller policy does not allow automatic activation of this exact model.');
        }
        if ($spec['requires_operator_override']) {
            throw new RuntimeException('Selected target requires /host activate '.$target.' --override; automatic activation cannot supply that override.');
        }
        $lifecycle = new CliHostLifecycle($this->store, $session['id'], $connection);
        if (($lease['phase'] ?? null) === 'owned') {
            // Existing release verifies exact current ownership before stopping anything.
            $lifecycle->release();
        }
        // Any exception stops selection here; another candidate is never started.
        $lifecycle->activate($target, capacityBinding: $proof['capacity_binding'] ?? null);

        return ['allowed' => true, 'selection_committed' => true];
    }
}
