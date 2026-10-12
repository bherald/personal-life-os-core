<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use App\Services\ModelHardwareAdmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only monthly audit for registered local model candidates.
 *
 * Discovery and routing stay table-owned. This command never pulls, removes,
 * promotes, or reconfigures a model; it only identifies the next eval lane.
 */
class OllamaCheckUpgrades extends Command
{
    protected $signature = 'ollama:check-upgrades
                            {--force : Send notification even when no evaluation candidates are present}
                            {--dry-run : Show the audit without sending a notification}
                            {--vram=6 : Maximum candidate VRAM in GB}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Audit table-registered Ollama candidates and require eval gates before promotion';

    public function handle(): int
    {
        $maxVram = max(1.0, (float) $this->option('vram'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $payload = $this->collect($maxVram);
        $shouldNotify = $force || $payload['status'] === 'evaluation_required';
        $payload['notification'] = [
            'dry_run' => $dryRun,
            'would_send' => $shouldNotify,
            'sent' => false,
        ];

        if ($dryRun || ! $shouldNotify || $payload['status'] === 'unavailable') {
            $this->render($payload);

            return $payload['status'] === 'unavailable' ? self::FAILURE : self::SUCCESS;
        }

        $payload['notification']['sent'] = $this->sendNotification($this->buildNotificationMessage($payload));
        $this->render($payload);

        if (! $payload['notification']['sent']) {
            return self::FAILURE;
        }

        Log::info('Ollama model readiness audit completed', [
            'status' => $payload['status'],
            'summary' => $payload['summary'],
            'notification_sent' => true,
        ]);

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function collect(float $maxVram): array
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('ollama_models')) {
            return $this->unavailablePayload($maxVram);
        }

        $instances = DB::table('llm_instances')
            ->whereIn('instance_type', ['ollama', 'local_llm'])
            ->orderBy('priority')
            ->get([
                'instance_id',
                'instance_name',
                'instance_type',
                'priority',
                'is_active',
                'is_healthy',
                'routability',
                'gpu_target',
                'host_affinity',
                'config',
            ]);

        $models = DB::table('ollama_models');
        $availableModels = (clone $models)->where('is_available', 1);
        $available = (clone $availableModels)->count();
        $vetted = (clone $availableModels)->where('status', 'vetted')->count();
        $testing = (clone $availableModels)->where('status', 'testing')->count();
        $unused = (clone $availableModels)->where('total_requests', 0)->count();
        $maxVramMb = (int) round($maxVram * 1024);

        $candidateRows = DB::table('ollama_models as models')
            ->leftJoin('llm_instances as instances', 'instances.id', '=', 'models.instance_id')
            ->where('models.is_available', 1)
            ->whereIn('models.status', ['discovered', 'testing'])
            ->where(function ($query) use ($maxVram, $maxVramMb): void {
                $query->where('models.vram_required_mb', '<=', $maxVramMb)
                    ->orWhere(function ($query) use ($maxVram): void {
                        $query->whereNull('models.vram_required_mb')
                            ->where(function ($query) use ($maxVram): void {
                                $query->whereNull('models.size_gb')
                                    ->orWhere('models.size_gb', '<=', $maxVram);
                            });
                    });
            })
            ->orderByDesc('models.quality_rating')
            ->orderBy('models.total_requests')
            ->orderBy('models.model_name')
            ->limit(12)
            ->get([
                'models.model_name',
                'models.profile',
                'models.status',
                'models.size_gb',
                'models.vram_required_mb',
                'models.quality_rating',
                'models.total_requests',
                'models.success_rate',
                'models.last_seen_at',
                'instances.instance_id as route_instance_id',
            ]);

        $candidates = $candidateRows->map(static fn (object $model): array => [
            'model_name' => (string) $model->model_name,
            'route_instance_id' => (string) ($model->route_instance_id ?? 'unassigned'),
            'profile' => (string) ($model->profile ?? 'unassigned'),
            'status' => (string) $model->status,
            'size_gb' => is_numeric($model->size_gb) ? (float) $model->size_gb : null,
            'vram_required_mb' => is_numeric($model->vram_required_mb) ? (int) $model->vram_required_mb : null,
            'quality_rating' => is_numeric($model->quality_rating) ? (int) $model->quality_rating : null,
            'total_requests' => (int) ($model->total_requests ?? 0),
            'success_rate' => is_numeric($model->success_rate) ? (float) $model->success_rate : null,
            'last_seen_at' => $model->last_seen_at ? (string) $model->last_seen_at : null,
        ])->all();

        $activeInstances = $instances
            ->filter(static fn (object $instance): bool => (bool) $instance->is_active)
            ->map(fn (object $instance): array => $this->instanceSummary($instance))
            ->values()
            ->all();
        $requestLog = $this->requestLogSummary();
        $hardwareAdmission = $this->hardwareAdmissionSummary($instances);
        $hardwareCandidates = count(array_filter(
            $hardwareAdmission,
            static fn (array $row): bool => $row['decision'] === 'candidate_only'
        ));
        $hardwareBlocked = count(array_filter(
            $hardwareAdmission,
            static fn (array $row): bool => str_starts_with((string) $row['decision'], 'blocked_')
        ));
        $status = $candidates !== [] || $hardwareCandidates > 0 ? 'evaluation_required' : 'observe';

        return [
            'schema' => 'plos.ollama_model_readiness_audit.v2',
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'mode' => 'observe',
            'read_only' => true,
            'table_owned' => true,
            'max_candidate_vram_gb' => $maxVram,
            'summary' => [
                'local_instances' => $instances->count(),
                'active_local_instances' => count($activeInstances),
                'healthy_active_instances' => count(array_filter(
                    $activeInstances,
                    static fn (array $instance): bool => $instance['is_healthy']
                )),
                'models_total' => (clone $models)->count(),
                'available_models' => $available,
                'vetted_models' => $vetted,
                'testing_models' => $testing,
                'zero_usage_available_models' => $unused,
                'evaluation_candidates' => count($candidates),
                'hardware_fit_candidates' => $hardwareCandidates,
                'hardware_admission_blocks' => $hardwareBlocked,
            ],
            'active_routes' => $activeInstances,
            'request_log_30d' => $requestLog,
            'evaluation_candidates' => $candidates,
            'hardware_admission' => $hardwareAdmission,
            'recommendation' => [
                'decision' => $hardwareCandidates > 0
                    ? 'confirm_one_published_fit_candidate_contract'
                    : ($unused > 0 ? 'evaluate_existing_before_installing' : 'no_new_model_evidence'),
                'install_recommended' => false,
                'promotion_recommended' => false,
                'reason' => $hardwareCandidates > 0
                    ? "{$hardwareCandidates} exact artifacts have published hardware fit but still need one bounded PLOS role contract before install/promotion."
                    : ($unused > 0
                        ? "{$unused} available registered models still lack usage evidence."
                        : 'No versioned evidence proves that a new model improves an active PLOS role.'),
                'required_gates' => [
                    'php artisan ollama:drift-check --json --compact --no-fail',
                    'official exact-artifact metadata and hardware envelope registered',
                    'one bounded PLOS role/tool/schema contract check',
                    'time-boxed canary with role telemetry',
                    'explicit rollback or deactivation path',
                ],
            ],
            'safety' => [
                'models_pulled' => false,
                'models_removed' => false,
                'routes_changed' => false,
                'environment_changed' => false,
                'services_restarted' => false,
            ],
        ];
    }

    /**
     * @param  Collection<int,object>  $instances
     * @return array<int,array<string,mixed>>
     */
    private function hardwareAdmissionSummary($instances): array
    {
        $service = app(ModelHardwareAdmissionService::class);
        $policy = $service->policy();
        $classes = is_array($policy['hardware_classes'] ?? null) ? $policy['hardware_classes'] : [];
        $rows = [];

        foreach ($instances as $instance) {
            $config = json_decode((string) ($instance->config ?? '{}'), true);
            $config = is_array($config) ? $config : [];
            $admission = is_array($config['model_admission'] ?? null) ? $config['model_admission'] : [];
            if (! filter_var($admission['enforce'] ?? false, FILTER_VALIDATE_BOOL)) {
                continue;
            }

            $hardwareClass = (string) ($admission['hardware_class'] ?? '');
            $classPolicy = is_array($classes[$hardwareClass] ?? null) ? $classes[$hardwareClass] : [];
            $modelPolicies = is_array($classPolicy['models'] ?? null) ? $classPolicy['models'] : [];

            foreach ($modelPolicies as $model => $modelPolicy) {
                if (! is_string($model) || ! is_array($modelPolicy)) {
                    continue;
                }
                $roles = array_values(array_filter((array) ($modelPolicy['roles'] ?? []), 'is_string'));
                $assessment = $service->assessAgainstPolicy(
                    $hardwareClass,
                    $model,
                    $roles[0] ?? null,
                    $policy,
                    (string) $instance->instance_id
                );
                $rows[] = [
                    'instance_id' => (string) $instance->instance_id,
                    'hardware_class' => $hardwareClass,
                    'model' => $model,
                    'roles' => $roles,
                    'decision' => $assessment['decision'],
                    'route_allowed' => $assessment['route_allowed'],
                    'hardware_feasible' => $assessment['hardware_feasible'],
                    'hardware_fit' => $assessment['hardware_fit'] ?? null,
                    'deployment_state' => $assessment['deployment_state'] ?? null,
                    'artifact_size_gb' => $assessment['artifact_size_gb'] ?? null,
                    'required_gates' => $assessment['required_gates'],
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['instance_id'], $a['decision'], $a['model']] <=> [$b['instance_id'], $b['decision'], $b['model']]);

        return $rows;
    }

    /** @return array<string,mixed> */
    private function instanceSummary(object $instance): array
    {
        $config = json_decode((string) ($instance->config ?? '{}'), true);
        $models = is_array($config['models'] ?? null) ? $config['models'] : [];

        return [
            'instance_id' => (string) $instance->instance_id,
            'instance_name' => (string) $instance->instance_name,
            'instance_type' => (string) $instance->instance_type,
            'priority' => (int) $instance->priority,
            'is_healthy' => (bool) $instance->is_healthy,
            'routability' => (string) $instance->routability,
            'gpu_target' => (string) ($instance->gpu_target ?? 'none'),
            'host_affinity' => (string) ($instance->host_affinity ?? ''),
            'role_models' => array_filter($models, static fn (mixed $model): bool => is_string($model) && $model !== ''),
        ];
    }

    /** @return array<string,mixed> */
    private function requestLogSummary(): array
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return ['available' => false, 'calls' => 0, 'distinct_models' => 0, 'failures' => 0];
        }

        $row = DB::table('llm_model_request_logs')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COUNT(DISTINCT model_name) AS distinct_models')
            ->selectRaw('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures')
            ->first();

        return [
            'available' => true,
            'calls' => (int) ($row->calls ?? 0),
            'distinct_models' => (int) ($row->distinct_models ?? 0),
            'failures' => (int) ($row->failures ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function unavailablePayload(float $maxVram): array
    {
        return [
            'schema' => 'plos.ollama_model_readiness_audit.v2',
            'generated_at' => now()->toIso8601String(),
            'status' => 'unavailable',
            'mode' => 'observe',
            'read_only' => true,
            'table_owned' => true,
            'max_candidate_vram_gb' => $maxVram,
            'summary' => [],
            'active_routes' => [],
            'request_log_30d' => ['available' => false],
            'evaluation_candidates' => [],
            'hardware_admission' => [],
            'recommendation' => [
                'decision' => 'inventory_unavailable',
                'install_recommended' => false,
                'promotion_recommended' => false,
                'required_gates' => [],
            ],
            'safety' => [
                'models_pulled' => false,
                'models_removed' => false,
                'routes_changed' => false,
                'environment_changed' => false,
                'services_restarted' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function render(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $summary = $payload['summary'];
        $this->info('PLOS Ollama Model Readiness Audit');
        $this->line('Status: '.strtoupper((string) $payload['status']));
        if ($payload['status'] === 'unavailable') {
            $this->error('The table-owned LLM inventory is unavailable; no recommendation was produced.');

            return;
        }

        $this->line(sprintf(
            'Local routes: %d active, %d healthy | Models: %d available, %d vetted, %d testing, %d zero-usage',
            $summary['active_local_instances'],
            $summary['healthy_active_instances'],
            $summary['available_models'],
            $summary['vetted_models'],
            $summary['testing_models'],
            $summary['zero_usage_available_models'],
        ));
        $this->line('Decision: '.$payload['recommendation']['decision']);
        $this->warn('No model install, removal, promotion, or route change is recommended by this command.');

        foreach ($payload['evaluation_candidates'] as $candidate) {
            $this->line(sprintf(
                '  - %s on %s [%s] requests=%d vram_mb=%s',
                $candidate['model_name'],
                $candidate['route_instance_id'],
                $candidate['profile'],
                $candidate['total_requests'],
                $candidate['vram_required_mb'] ?? 'unknown',
            ));
        }

        foreach ($payload['hardware_admission'] as $assessment) {
            $this->line(sprintf(
                '  - admission %s/%s: %s (%s)',
                $assessment['instance_id'],
                $assessment['model'],
                $assessment['decision'],
                $assessment['hardware_fit'] ?? 'unknown',
            ));
        }

        $this->line('Required gates:');
        foreach ($payload['recommendation']['required_gates'] as $gate) {
            $this->line('  - '.$gate);
        }

        if ($payload['notification']['dry_run']) {
            $this->warn('[DRY RUN] Notification not sent.');
        } elseif ($payload['notification']['sent']) {
            $this->info('Readiness notification sent.');
        } elseif (! $payload['notification']['would_send']) {
            $this->line('No notification required.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function buildNotificationMessage(array $payload): string
    {
        $summary = $payload['summary'];

        return implode("\n", [
            'PLOS OLLAMA MODEL READINESS',
            'Status: '.strtoupper((string) $payload['status']),
            sprintf(
                'Available %d | Vetted %d | Testing %d | Zero-use %d',
                $summary['available_models'],
                $summary['vetted_models'],
                $summary['testing_models'],
                $summary['zero_usage_available_models'],
            ),
            'Decision: '.$payload['recommendation']['decision'],
            'No install or promotion is approved.',
            'Next: verify drift, one exact role contract, bounded canary, privacy, and rollback.',
        ]);
    }

    private function sendNotification(string $message): bool
    {
        try {
            $result = (new NotificationController)->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => 'Ollama Model Readiness Audit',
                'message' => $message,
                'priority' => 0,
                'format_type' => 'monospace',
            ]);

            return (bool) ($result['success'] ?? false);
        } catch (Throwable $e) {
            Log::error('Failed to send Ollama model readiness notification', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
