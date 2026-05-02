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
 *   - destructive: Escalate to Claude Code session (not executable via UI)
 */
class RemediationRegistryService
{
    private const CACHE_KEY = 'remediation_actions_registry';
    private const CACHE_TTL = 3600;

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
                $rows = DB::select("
                    SELECT id, finding_type, action_type, action_target, action_params,
                           risk_level, description, requires_confirmation, cooldown_minutes,
                           last_executed_at, execution_count, success_count, failure_count
                    FROM remediation_actions
                    WHERE is_active = 1
                    ORDER BY finding_type
                ");

                return array_map(fn($row) => [
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
            fn($a) => $a['risk_level'] === $riskLevel
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

            DB::update("
                UPDATE remediation_actions
                SET execution_count = execution_count + 1,
                    success_count = success_count + ?,
                    failure_count = failure_count + ?,
                    last_executed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [$successIncrement, $failureIncrement, $actionId]);

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
            DB::insert("
                INSERT INTO remediation_actions
                (finding_type, action_type, action_target, action_params, risk_level,
                 description, requires_confirmation, cooldown_minutes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ", [
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

            $inserted = DB::selectOne("SELECT LAST_INSERT_ID() as id");
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
            $affected = DB::update("
                UPDATE remediation_actions SET is_active = 0, updated_at = NOW() WHERE id = ?
            ", [$actionId]);

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
                "SELECT id, action_type, action_target, action_params, risk_level, description, requires_confirmation, cooldown_minutes
                 FROM remediation_actions
                 WHERE finding_type = ?",
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
                    "UPDATE remediation_actions
                     SET action_type = ?, action_target = ?, action_params = ?, risk_level = ?,
                         description = ?, requires_confirmation = ?, cooldown_minutes = ?, updated_at = NOW()
                     WHERE id = ?",
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

    /**
     * Known remediation action definitions.
     */
    private function getSeedDefinitions(): array
    {
        return [
            // === READ risk (auto-healable) ===
            [
                'finding_type' => 'circuit_breaker_open',
                'action_type' => 'service_method',
                'action_target' => 'App\\Services\\AutoHealService::resetAllCircuits',
                'action_params' => [],
                'risk_level' => 'read',
                'description' => 'Reset all open LLM circuit breakers to closed state',
                'requires_confirmation' => false,
                'cooldown_minutes' => 15,
            ],
            [
                'finding_type' => 'stalled_job',
                'action_type' => 'service_method',
                'action_target' => 'App\\Services\\ScheduledJobService::fixStuckJobs',
                'action_params' => [],
                'risk_level' => 'read',
                'description' => 'Reset stuck scheduled jobs (running beyond timeout + 15min)',
                'requires_confirmation' => false,
                'cooldown_minutes' => 30,
            ],
            [
                'finding_type' => 'stuck_lock',
                'action_type' => 'service_method',
                'action_target' => 'App\\Services\\AutoHealService::clearStaleLocks',
                'action_params' => [],
                'risk_level' => 'read',
                'description' => 'Clear stale Redis locks (ollama_busy_lock, whisper_gpu_lock)',
                'requires_confirmation' => false,
                'cooldown_minutes' => 10,
            ],
            [
                'finding_type' => 'horizon_down',
                'action_type' => 'service_method',
                'action_target' => 'App\\Services\\AutoHealService::recoverHorizonService',
                'action_params' => [],
                'risk_level' => 'write',
                'description' => 'Restart Horizon through systemd when available, with fallback termination for external supervisors',
                'requires_confirmation' => true,
                'cooldown_minutes' => 5,
            ],

            // === WRITE risk (one-click approve in Review Hub) ===
            [
                'finding_type' => 'enrichment_pipeline_stalled',
                'action_type' => 'artisan_command',
                'action_target' => 'scheduled-job:run-now',
                'action_params' => ['jobName' => 'file_enrich_ai'],
                'risk_level' => 'write',
                'description' => 'Restart the file AI enrichment pipeline',
                'requires_confirmation' => true,
                'cooldown_minutes' => 60,
            ],
            [
                'finding_type' => 'rag_indexing_stalled',
                'action_type' => 'artisan_command',
                'action_target' => 'scheduled-job:run-now',
                'action_params' => ['jobName' => 'rag_sentence_indexing'],
                'risk_level' => 'write',
                'description' => 'Restart the RAG sentence indexing pipeline',
                'requires_confirmation' => true,
                'cooldown_minutes' => 60,
            ],
            [
                'finding_type' => 'youtube_rag_stalled',
                'action_type' => 'artisan_command',
                'action_target' => 'scheduled-job:run-now',
                'action_params' => ['jobName' => 'rag_sentence_indexing'],
                'risk_level' => 'write',
                'description' => 'Trigger RAG indexing for YouTube transcripts',
                'requires_confirmation' => true,
                'cooldown_minutes' => 60,
            ],
            [
                'finding_type' => 'research_approval_low',
                'action_type' => 'service_method',
                'action_target' => 'App\\Services\\Research\\SourceOptimizationService::runSelfHealing',
                'action_params' => [],
                'risk_level' => 'write',
                'description' => 'Run research pipeline self-healing (reset filters, clear quarantine)',
                'requires_confirmation' => true,
                'cooldown_minutes' => 120,
            ],
            [
                'finding_type' => 'face_clustering_stale',
                'action_type' => 'artisan_command',
                'action_target' => 'scheduled-job:run-now',
                'action_params' => ['jobName' => 'face_recluster'],
                'risk_level' => 'write',
                'description' => 'Trigger face re-clustering job',
                'requires_confirmation' => true,
                'cooldown_minutes' => 120,
            ],
            [
                'finding_type' => 'knowledge_graph_stale',
                'action_type' => 'artisan_command',
                'action_target' => 'scheduled-job:run-now',
                'action_params' => ['jobName' => 'knowledge_graph_build'],
                'risk_level' => 'write',
                'description' => 'Trigger knowledge graph build job',
                'requires_confirmation' => true,
                'cooldown_minutes' => 120,
            ],

            // === DESTRUCTIVE risk (escalate only) ===
            [
                'finding_type' => 'duplicate_files',
                'action_type' => 'service_method',
                'action_target' => 'ESCALATE',
                'action_params' => [],
                'risk_level' => 'destructive',
                'description' => 'Duplicate file resolution requires human review with context (paths, dates, usage). Not auto-executable.',
                'requires_confirmation' => true,
                'cooldown_minutes' => 0,
            ],
        ];
    }
}
