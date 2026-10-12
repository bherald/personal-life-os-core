<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;

/** Synced compute discovery only; never opens a connection or grants execution. */
final class CliComputeSelection
{
    public function __construct(private readonly CliConfiguration $configuration) {}

    public function inspect(array $session, ?string $capability = null): array
    {
        if ($capability !== null && ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $capability)) {
            throw new InvalidArgumentException('Use /compute route CAPABILITY with an exact registry capability name.');
        }
        $selected = $this->configuration->effective($session)['values']['cli.compute_instance'] ?? 'auto';
        $rows = $this->configuration->definitions($session, 'compute_instances');
        $counts = array_count_values(array_filter(array_column($rows, 'instance_id'), 'is_string'));
        $candidates = [];
        foreach ($rows as $row) {
            $id = $row['instance_id'] ?? null;
            if ($selected !== 'auto' && $id !== $selected) {
                continue;
            }
            $capabilities = CliSettingValue::decode($row['capabilities'] ?? [], 'json');
            $validCapabilities = is_array($capabilities) && array_is_list($capabilities)
                && count(array_filter($capabilities, 'is_string')) === count($capabilities);
            $reasons = [];
            if (! is_string($id) || $id === '' || ($counts[$id] ?? 0) !== 1) {
                $reasons[] = 'missing_or_ambiguous_instance_identity';
            }
            if (! in_array($row['is_active'] ?? null, [true, 1, '1'], true)) {
                $reasons[] = 'registry_inactive';
            }
            if (! in_array($row['is_healthy'] ?? null, [true, 1, '1'], true)) {
                $reasons[] = 'registry_unhealthy_or_unknown';
            }
            if (($row['circuit_state'] ?? null) !== 'closed') {
                $reasons[] = 'registry_circuit_not_closed';
            }
            if (! $validCapabilities) {
                $reasons[] = 'invalid_registry_capabilities';
            } elseif ($capability !== null && ! in_array($capability, $capabilities, true)) {
                $reasons[] = 'capability_not_advertised';
            }
            $candidates[] = [
                'instance_id' => $id, 'host' => $row['host'] ?? null,
                'capabilities' => $validCapabilities ? $capabilities : [],
                'priority' => $row['priority'] ?? null,
                'configuration_eligible' => $reasons === [], 'reasons' => $reasons,
                // Production's is_local flag is relative to production, not this CLI.
                'registry_is_local' => $row['is_local'] ?? null,
                'execution_location_verified' => false,
                'registry_last_health_check' => $row['last_health_check'] ?? null,
                'live_health_checked' => false, 'runtime_admission_checked' => false,
            ];
        }
        usort($candidates, fn ($a, $b) => (($a['priority'] ?? PHP_INT_MAX) <=> ($b['priority'] ?? PHP_INT_MAX))
            ?: strcmp((string) $a['instance_id'], (string) $b['instance_id']));

        return ['selection' => $selected, 'mode' => $selected === 'auto' ? 'automatic' : 'manual',
            'capability' => $capability, 'selection_present' => $selected === 'auto' || count($candidates) === 1,
            'ordering' => 'registry_priority_then_instance_id', 'candidates' => $candidates,
            'runtime_admission_checked' => false,
            'message' => 'Registry discovery only. Execution still needs a bound host, current health, capacity and permission. Save optionally with /save-default cli.compute_instance.'];
    }
}
