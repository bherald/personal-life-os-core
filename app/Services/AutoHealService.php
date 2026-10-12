<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * INF-10b: Auto-Heal Engine
 *
 * Scans for active issues and automatically executes `read`-risk remediation
 * actions from the remediation registry. Runs in OpsMaintenanceJob (4 AM daily).
 *
 * Detection → Registry Lookup → Cooldown Check → Execute → Log
 *
 * Only `read` risk level actions are auto-executed. `write` and `destructive`
 * are left for Review Hub or an operator-owned maintenance session.
 */
class AutoHealService
{
    private const ALLOWED_ARTISAN_COMMANDS = [];

    private RemediationRegistryService $registry;

    public function __construct(RemediationRegistryService $registry)
    {
        $this->registry = $registry;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Run the auto-heal cycle: detect issues, match to read-risk remediations, execute.
     *
     * @return array Summary of detections and actions taken
     */
    public function run(): array
    {
        $results = [
            'detected' => [],
            'healed' => [],
            'skipped' => [],
            'failed' => [],
            'held' => [],
        ];

        $detectors = $this->getDetectors();

        foreach ($detectors as $findingType => $detector) {
            try {
                $detected = $detector();
                if (! $detected) {
                    continue;
                }

                $results['detected'][] = $findingType;

                $action = $this->registry->getActionForFinding($findingType);
                if (! $action) {
                    $results['skipped'][] = ['type' => $findingType, 'reason' => 'No remediation registered'];

                    continue;
                }

                if ($action['risk_level'] !== 'read') {
                    $results['skipped'][] = ['type' => $findingType, 'reason' => "Risk level '{$action['risk_level']}' not auto-healable"];

                    continue;
                }

                if ($this->registry->isInCooldown($action)) {
                    $results['skipped'][] = ['type' => $findingType, 'reason' => 'In cooldown'];

                    continue;
                }

                $execResult = $this->execute($action);

                if ($execResult['success']) {
                    $results['healed'][] = ['type' => $findingType, 'action' => $action['description'], 'detail' => $execResult['detail'] ?? ''];
                    $this->registry->recordExecution($action['id'], true);
                } elseif (($execResult['held'] ?? false) === true) {
                    $results['held'][] = ['type' => $findingType, 'reason_code' => $execResult['error']];
                } else {
                    $results['failed'][] = ['type' => $findingType, 'error' => $execResult['error']];
                    $this->registry->recordExecution($action['id'], false);
                }

            } catch (\Throwable $e) {
                $results['failed'][] = ['type' => $findingType, 'error' => $e->getMessage()];
                Log::error('AutoHeal: Detector/executor failed', [
                    'finding_type' => $findingType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($results['healed']) || ! empty($results['failed'])) {
            Log::info('AutoHeal: Cycle complete', [
                'detected' => count($results['detected']),
                'healed' => count($results['healed']),
                'skipped' => count($results['skipped']),
                'failed' => count($results['failed']),
            ]);
        }

        return $results;
    }

    // =========================================================================
    // Execution engine
    // =========================================================================

    /**
     * Execute a remediation action.
     *
     * @return array{success: bool, detail?: string, error?: string}
     */
    public function execute(array $action): array
    {
        return match ($action['action_type']) {
            'artisan_command' => $this->executeArtisanCommand($action),
            'service_method' => $this->executeServiceMethod($action),
            'sql_update' => $this->executeSqlUpdate($action),
            default => ['success' => false, 'error' => "Unknown action type: {$action['action_type']}"],
        };
    }

    private function executeArtisanCommand(array $action): array
    {
        try {
            $command = $action['action_target'];
            $params = $action['action_params'] ?? [];

            if (! in_array($command, self::ALLOWED_ARTISAN_COMMANDS, true)) {
                return ['success' => false, 'error' => "Artisan command not allowed for auto-heal: {$command}"];
            }

            Artisan::call($command, $params);
            $output = trim(Artisan::output());

            return ['success' => true, 'detail' => mb_substr($output, 0, 200)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeServiceMethod(array $action): array
    {
        try {
            $target = $action['action_target'];

            if ($target === 'ESCALATE') {
                return ['success' => false, 'error' => 'Action requires escalation — not auto-executable'];
            }

            if (! str_contains($target, '::')) {
                return ['success' => false, 'error' => "Invalid service method target: {$target}"];
            }

            [$class, $method] = explode('::', $target, 2);

            if (! class_exists($class)) {
                return ['success' => false, 'error' => "Service class not found: {$class}"];
            }

            $service = app($class);

            if (! method_exists($service, $method)) {
                return ['success' => false, 'error' => "Method not found: {$class}::{$method}"];
            }

            $result = $service->$method();
            if ($class === ScheduledJobService::class && $method === 'fixStuckJobs' && $result === 0) {
                return ['success' => false, 'held' => true, 'error' => 'scheduled_job_work_end_unproven'];
            }
            if ($class === self::class && in_array($method, ['clearStaleLocks', 'resetAllCircuits'], true) && ($result['success'] ?? false) !== true) {
                return ['success' => false, 'held' => true, 'error' => $result['reason_code']];
            }

            $detail = is_array($result) ? json_encode($result) : (string) $result;

            return ['success' => true, 'detail' => mb_substr($detail, 0, 200)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeSqlUpdate(array $action): array
    {
        try {
            $sql = $action['action_target'];
            $params = $action['action_params'] ?? [];

            $affected = DB::update($sql, $params);

            return ['success' => true, 'detail' => "{$affected} rows affected"];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Remediation methods (called by service_method action_target)
    // =========================================================================

    /** Circuit recovery belongs to normal provider admission, not an unconditional reset. */
    public function resetAllCircuits(): array
    {
        return ['success' => false, 'reason_code' => 'circuit_recovery_proof_required', 'reset' => 0];
    }

    /** Observe GPU holds; age and job absence never prove physical work ended. */
    public function clearStaleLocks(): array
    {
        $held = [];
        foreach (['ollama_busy_lock', 'whisper_gpu_lock'] as $key) {
            if (Cache::has($key)) {
                $held[] = $key;
            }
        }

        return ['success' => $held === [], 'reason_code' => $held === [] ? null : 'lock_work_end_unproven',
            'cleared' => [], 'count' => 0, 'held' => $held];
    }

    /**
     * Recover Horizon using the current production topology.
     */
    public function recoverHorizonService(): array
    {
        try {
            $enabled = trim(Process::timeout(5)->run([
                'systemctl',
                'is-enabled',
                'laravel-horizon.service',
            ])->output());

            if (in_array($enabled, ['enabled', 'static'], true)) {
                $restart = Process::timeout(15)->run([
                    'sudo',
                    'systemctl',
                    'restart',
                    'laravel-horizon.service',
                ]);
                $status = trim(Process::timeout(5)->run([
                    'systemctl',
                    'is-active',
                    'laravel-horizon.service',
                ])->output());

                if ($status === 'active') {
                    return ['restarted' => true, 'message' => 'laravel-horizon.service restarted'];
                }

                return [
                    'error' => trim($restart->errorOutput()) ?: trim($restart->output()) ?: 'Failed to restart laravel-horizon.service',
                ];
            }

            Artisan::call('horizon:terminate');
            $output = trim(Artisan::output());

            return [
                'restarted' => false,
                'message' => $output !== '' ? $output : 'Issued horizon:terminate; verify external supervisor restarts Horizon',
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Detectors — each returns true if the issue is present
    // =========================================================================

    /**
     * Get all issue detectors. Each returns bool (true = issue detected).
     *
     * @return array<string, callable(): bool>
     */
    public function getDetectors(): array
    {
        return [
            'circuit_breaker_open' => fn () => $this->detectOpenCircuits(),
            'stalled_job' => fn () => $this->detectStalledJobs(),
            'stuck_lock' => fn () => $this->detectStuckLocks(),
            'horizon_down' => fn () => $this->detectHorizonDown(),
        ];
    }

    /**
     * Detect any open LLM circuit breakers.
     */
    public function detectOpenCircuits(): bool
    {
        try {
            $open = DB::select("
                SELECT id FROM llm_instances
                WHERE is_active = 1 AND circuit_state = 'open'
                LIMIT 1
            ");

            return ! empty($open);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Detect any scheduled jobs stuck in 'running' beyond their timeout.
     */
    public function detectStalledJobs(): bool
    {
        try {
            $stalled = DB::select("
                SELECT id FROM scheduled_jobs
                WHERE last_run_status = 'running'
                  AND stall_exempt = 0
                  AND COALESCE(job_type, '') <> 'agent_task'
                  AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 60) + 15 MINUTE)
                LIMIT 5
            ");
            // Filter out jobs with active adaptive timeout extensions
            $stalled = array_filter($stalled, function ($s) {
                $deadline = Cache::get("scheduler:job:{$s->id}:deadline");

                return ! ($deadline && $deadline > time());
            });

            return ! empty($stalled);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A retained lock is an observation, not evidence authorizing stale-lock remediation. */
    public function detectStuckLocks(): bool
    {
        foreach (['ollama_busy_lock', 'whisper_gpu_lock'] as $key) {
            if (Cache::has($key)) {
                Log::notice('AutoHeal: GPU custody retained', ['lock' => $key, 'reason_code' => 'lock_work_end_unproven']);
            }
        }

        return false;
    }

    /**
     * Detect if Horizon is not running.
     */
    public function detectHorizonDown(): bool
    {
        try {
            $result = Process::path(base_path())
                ->timeout(10)
                ->run([PHP_BINARY, 'artisan', 'horizon:status']);
            $output = trim($result->output()."\n".$result->errorOutput());

            return str_contains(strtolower($output), 'not running') || str_contains(strtolower($output), 'inactive');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
