<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INF-10a: Remediation Registry Service
 *
 * Manages the mapping between finding types and their executable remediation
 * actions. Foundation for the Closed-Loop Self-Healing Framework.
 *
 * Risk levels (reuse AgentGuardrailService pattern):
 *   - read:        Safe, auto-executable (circuit reset, lock clear, status check)
 *   - write:       Needs human approval via Review Hub button
 *   - destructive: Escalate to an operator-owned maintenance session
 */
class RemediationRegistryService
{
    private const CACHE_KEY = 'remediation_actions_registry';

    private const CACHE_TTL = 3600;

    private const SEED_POLICY_KEY = 'ops.remediation_registry_seed_policy';

    private const SEED_POLICY_SCHEMA = 'plos.remediation_registry_seed_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    // =========================================================================
    // Lookup
    // =========================================================================

    /**
     * Get the remediation action for a finding type.
     *
     * @return array|null Action definition or null if none registered
     */
    public function getActionForFinding(string $findingType): ?array
    {
        $all = $this->getAllActions();

        foreach ($all as $action) {
            if ($action['finding_type'] === $findingType) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Get all active remediation actions, cached.
     *
     * @return array[] List of action definitions
     */
    public function getAllActions(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                $rows = DB::select('
                    SELECT id, finding_type, action_type, action_target, action_params,
                           risk_level, description, requires_confirmation, cooldown_minutes,
                           last_executed_at, execution_count, success_count, failure_count
                    FROM remediation_actions
                    WHERE is_active = 1
                    ORDER BY finding_type
                ');

                return array_map(fn ($row) => [
                    'id' => (int) $row->id,
                    'finding_type' => $row->finding_type,
                    'action_type' => $row->action_type,
                    'action_target' => $row->action_target,
                    'action_params' => json_decode($row->action_params ?? '{}', true) ?: [],
                    'risk_level' => $row->risk_level,
                    'description' => $row->description,
                    'requires_confirmation' => (bool) $row->requires_confirmation,
                    'cooldown_minutes' => (int) $row->cooldown_minutes,
                    'last_executed_at' => $row->last_executed_at,
                    'execution_count' => (int) $row->execution_count,
                    'success_count' => (int) $row->success_count,
                    'failure_count' => (int) $row->failure_count,
                ], $rows);
            });
        } catch (\Exception $e) {
            Log::warning('RemediationRegistry: Failed to load actions', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get actions filtered by risk level.
     */
    public function getActionsByRisk(string $riskLevel): array
    {
        return array_values(array_filter(
            $this->getAllActions(),
            fn ($a) => $a['risk_level'] === $riskLevel
        ));
    }

    /**
     * Check if an action is within its cooldown period.
     */
    public function isInCooldown(array $action): bool
    {
        if ($action['cooldown_minutes'] <= 0 || empty($action['last_executed_at'])) {
            return false;
        }

        $lastExec = strtotime($action['last_executed_at']);
        $cooldownEnd = $lastExec + ($action['cooldown_minutes'] * 60);

        return time() < $cooldownEnd;
    }

    // =========================================================================
    // Recording
    // =========================================================================

    /**
     * Record that a remediation action was executed.
     */
    public function recordExecution(int $actionId, bool $success): void
    {
        try {
            $successIncrement = $success ? 1 : 0;
            $failureIncrement = $success ? 0 : 1;

            DB::update('
                UPDATE remediation_actions
                SET execution_count = execution_count + 1,
                    success_count = success_count + ?,
                    failure_count = failure_count + ?,
                    last_executed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ', [$successIncrement, $failureIncrement, $actionId]);

            Cache::forget(self::CACHE_KEY);
        } catch (\Exception $e) {
            Log::warning('RemediationRegistry: Failed to record execution', [
                'action_id' => $actionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * Register a new remediation action.
     */
    public function register(array $definition): ?int
    {
        try {
            DB::insert('
                INSERT INTO remediation_actions
                (finding_type, action_type, action_target, action_params, risk_level,
                 description, requires_confirmation, cooldown_minutes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $definition['finding_type'],
                $definition['action_type'],
                $definition['action_target'],
                json_encode($definition['action_params'] ?? []),
                $definition['risk_level'] ?? 'read',
                $definition['description'],
                $definition['requires_confirmation'] ?? false,
                $definition['cooldown_minutes'] ?? 0,
            ]);

            Cache::forget(self::CACHE_KEY);

            $inserted = DB::selectOne('SELECT LAST_INSERT_ID() as id');

            return $inserted ? (int) $inserted->id : null;
        } catch (\Exception $e) {
            Log::error('RemediationRegistry: Failed to register action', [
                'finding_type' => $definition['finding_type'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Deactivate a remediation action.
     */
    public function deactivate(int $actionId): bool
    {
        try {
            $affected = DB::update('
                UPDATE remediation_actions SET is_active = 0, updated_at = NOW() WHERE id = ?
            ', [$actionId]);

            Cache::forget(self::CACHE_KEY);

            return $affected > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // Statistics
    // =========================================================================

    /**
     * Get registry statistics.
     */
    public function getStatistics(): array
    {
        try {
            $stats = DB::selectOne("
                SELECT
                    COUNT(*) as total,
                    SUM(is_active) as active,
                    SUM(CASE WHEN risk_level = 'read' THEN 1 ELSE 0 END) as read_risk,
                    SUM(CASE WHEN risk_level = 'write' THEN 1 ELSE 0 END) as write_risk,
                    SUM(CASE WHEN risk_level = 'destructive' THEN 1 ELSE 0 END) as destructive_risk,
                    SUM(execution_count) as total_executions,
                    SUM(success_count) as total_successes,
                    SUM(failure_count) as total_failures
                FROM remediation_actions
            ");

            return [
                'total' => (int) ($stats->total ?? 0),
                'active' => (int) ($stats->active ?? 0),
                'by_risk' => [
                    'read' => (int) ($stats->read_risk ?? 0),
                    'write' => (int) ($stats->write_risk ?? 0),
                    'destructive' => (int) ($stats->destructive_risk ?? 0),
                ],
                'executions' => [
                    'total' => (int) ($stats->total_executions ?? 0),
                    'successes' => (int) ($stats->total_successes ?? 0),
                    'failures' => (int) ($stats->total_failures ?? 0),
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Seed
    // =========================================================================

    /**
     * Seed the registry with known remediation actions.
     *
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function seed(): array
    {
        $definitions = $this->getSeedDefinitions();
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($definitions as $def) {
            $exists = DB::selectOne(
                'SELECT id, action_type, action_target, action_params, risk_level, description, requires_confirmation, cooldown_minutes
                 FROM remediation_actions
                 WHERE finding_type = ?',
                [$def['finding_type']]
            );

            if ($exists) {
                $current = [
                    'action_type' => $exists->action_type,
                    'action_target' => $exists->action_target,
                    'action_params' => json_decode($exists->action_params ?? '{}', true) ?: [],
                    'risk_level' => $exists->risk_level,
                    'description' => $exists->description,
                    'requires_confirmation' => (bool) $exists->requires_confirmation,
                    'cooldown_minutes' => (int) $exists->cooldown_minutes,
                ];

                $desired = [
                    'action_type' => $def['action_type'],
                    'action_target' => $def['action_target'],
                    'action_params' => $def['action_params'] ?? [],
                    'risk_level' => $def['risk_level'] ?? 'read',
                    'description' => $def['description'],
                    'requires_confirmation' => (bool) ($def['requires_confirmation'] ?? false),
                    'cooldown_minutes' => (int) ($def['cooldown_minutes'] ?? 0),
                ];

                if ($current === $desired) {
                    $skipped++;

                    continue;
                }

                DB::update(
                    'UPDATE remediation_actions
                     SET action_type = ?, action_target = ?, action_params = ?, risk_level = ?,
                         description = ?, requires_confirmation = ?, cooldown_minutes = ?, updated_at = NOW()
                     WHERE id = ?',
                    [
                        $desired['action_type'],
                        $desired['action_target'],
                        json_encode($desired['action_params']),
                        $desired['risk_level'],
                        $desired['description'],
                        $desired['requires_confirmation'],
                        $desired['cooldown_minutes'],
                        $exists->id,
                    ]
                );

                $updated++;

                continue;
            }

            $this->register($def);
            $inserted++;
        }

        Cache::forget(self::CACHE_KEY);

        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** @return list<array<string, mixed>> */
    private function getSeedDefinitions(): array
    {
        $policy = $this->systemConfig->get(self::SEED_POLICY_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SEED_POLICY_SCHEMA) {
            throw new \RuntimeException('Remediation registry seed policy is unavailable or has an unsupported schema.');
        }

        $definitions = $policy['actions'] ?? null;
        if (! is_array($definitions) || $definitions === [] || ! array_is_list($definitions)) {
            throw new \RuntimeException('Remediation registry seed policy must contain a non-empty action list.');
        }

        $validated = [];
        $findingTypes = [];
        foreach ($definitions as $index => $definition) {
            if (! is_array($definition)) {
                throw new \RuntimeException("Remediation registry action {$index} must be an object.");
            }

            $findingType = $definition['finding_type'] ?? null;
            $actionType = $definition['action_type'] ?? null;
            $actionTarget = $definition['action_target'] ?? null;
            $actionParams = $definition['action_params'] ?? null;
            $riskLevel = $definition['risk_level'] ?? null;
            $description = $definition['description'] ?? null;
            $requiresConfirmation = $definition['requires_confirmation'] ?? null;
            $cooldownMinutes = $definition['cooldown_minutes'] ?? null;

            if (! is_string($findingType) || $findingType === '' || strlen($findingType) > 100) {
                throw new \RuntimeException("Remediation registry action {$index} has an invalid finding_type.");
            }
            if (isset($findingTypes[$findingType])) {
                throw new \RuntimeException("Remediation registry seed policy repeats finding_type {$findingType}.");
            }
            if (! in_array($actionType, ['artisan_command', 'service_method', 'sql_update'], true)) {
                throw new \RuntimeException("Remediation registry action {$index} has an invalid action_type.");
            }
            if (! is_string($actionTarget) || $actionTarget === '' || strlen($actionTarget) > 500) {
                throw new \RuntimeException("Remediation registry action {$index} has an invalid action_target.");
            }
            if (! is_array($actionParams)) {
                throw new \RuntimeException("Remediation registry action {$index} has invalid action_params.");
            }
            if (! in_array($riskLevel, ['read', 'write', 'destructive'], true)) {
                throw new \RuntimeException("Remediation registry action {$index} has an invalid risk_level.");
            }
            if (! is_string($description) || $description === '' || strlen($description) > 500) {
                throw new \RuntimeException("Remediation registry action {$index} has an invalid description.");
            }
            if (! is_bool($requiresConfirmation)) {
                throw new \RuntimeException("Remediation registry action {$index} has invalid requires_confirmation.");
            }
            if (! is_int($cooldownMinutes) || $cooldownMinutes < 0 || $cooldownMinutes > 4294967295) {
                throw new \RuntimeException("Remediation registry action {$index} has invalid cooldown_minutes.");
            }

            $findingTypes[$findingType] = true;
            $validated[] = $definition;
        }

        return $validated;
    }
}
