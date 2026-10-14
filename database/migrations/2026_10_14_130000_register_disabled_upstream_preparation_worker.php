<?php

use App\Services\UpstreamPrivatePreparationAdmissionService;
use App\Services\UpstreamPrivatePreparationWorkerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry') || ! Schema::hasTable('system_configs')) {
            return;
        }
        DB::transaction(function (): void {
            DB::table('system_configs')->insertOrIgnore(['section' => 'ops',
                'config_key' => UpstreamPrivatePreparationWorkerService::CYCLE_POLICY_KEY,
                'config_value' => json_encode(['schema' => 'plos.upstream_worker_cycle_policy.v1', 'enabled' => false,
                    'max_items' => 1, 'max_wall_seconds' => 600, 'budget_caps' => ['max_items' => 1, 'max_wall_seconds' => 600]], JSON_THROW_ON_ERROR),
                'data_type' => 'json', 'description' => 'Default-disabled bounded recurring framework consumption; no replay or worker activation.',
                'created_at' => now(), 'updated_at' => now()]);
            $selectors = ['workKey' => ['type' => 'string', 'required' => true]];
            $tools = [
                'upstream_prepare_worker' => ['runWorker', $selectors + ['taskId' => ['type' => 'string', 'required' => true],
                    'attempt' => ['type' => 'integer', 'required' => true], 'generation' => ['type' => 'integer', 'required' => true]]],
                'upstream_launch_worker' => ['launchWorker', $selectors],
                'upstream_reconcile_worker' => ['reconcileWorkerOutcome', $selectors + ['expectedLaunchHash' => ['type' => 'string', 'required' => true],
                    'dryRun' => ['type' => 'boolean', 'required' => false, 'default' => true]]],
                'upstream_ready_review' => ['deliverReadyReview', $selectors],
            ];
            foreach ($tools as $name => [$method, $parameters]) {
                DB::table('agent_tool_registry')->insertOrIgnore([
                    'name' => $name, 'service_class' => UpstreamPrivatePreparationWorkerService::class, 'method' => $method,
                    'description' => 'Exact server-owned upstream preparation boundary; supervisor/complete source/custody/artifact/rollback proof required. No public publication or automatic replay.',
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR), 'permissions' => json_encode(['system:write'], JSON_THROW_ON_ERROR),
                    'risk_level' => 'write', 'category' => 'operations', 'requires_confirmation' => true, 'enabled' => false,
                    'availability_status' => 'disabled', 'privacy_class' => 'private_internal', 'allows_private_data' => true, 'source' => 'config',
                    'notes' => 'Disabled source registration; no target/journal/grant/model/schedule activation.', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $frameworkQuery = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services');
            $frameworkRow = $frameworkQuery->lockForUpdate()->first();
            if ($frameworkRow !== null) {
                $framework = json_decode($frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
                foreach (array_keys($tools) as $name) {
                    if (! isset($framework['tools'][$name])) {
                        $framework['tools'][$name] = ['target' => 'workflow', 'tree_scoped' => false, 'references' => [], 'durable_receipts' => true];
                    }
                }
                $frameworkQuery->where('config_value', $frameworkRow->config_value)->update([
                    'config_value' => json_encode($framework, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $query = DB::table('system_configs')->where('section', 'ops')->where('config_key', UpstreamPrivatePreparationAdmissionService::POLICY_KEY);
            $row = $query->lockForUpdate()->first();
            if ($row !== null) {
                $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
                if (($policy['schema'] ?? null) === UpstreamPrivatePreparationAdmissionService::POLICY_SCHEMA
                    && ! array_key_exists('worker_execution_enabled', $policy)) {
                    $policy['worker_execution_enabled'] = false;
                    $query->where('config_value', $row->config_value)->update([
                        'config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
            }
            // A disabled tool registration alone does not grant transport or worker target
            // authority. Root must qualify its exact existing framework rule,
            // durable private journal and scoped skill only after qualification.
            if (Schema::hasTable('review_type_registry')) {
                DB::table('review_type_registry')->insertOrIgnore([
                    'name' => 'upstream_preparation', 'label' => 'Prepared upstream work', 'icon' => 'code-bracket', 'category' => 'agent',
                    'source_table' => 'agent_review_queue', 'source_connection' => 'mysql',
                    'count_sql' => "SELECT COUNT(*) AS total, SUM(priority >= 1) AS high_priority FROM agent_review_queue WHERE status = 'pending' AND review_type = 'upstream_preparation'",
                    'fetch_sql' => "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND review_type = 'upstream_preparation' ORDER BY priority DESC, created_at ASC LIMIT 100",
                    'approve_sql' => "UPDATE agent_review_queue SET status = 'approved', reviewed_at = NOW(), updated_at = NOW() WHERE id = ? AND review_type = 'upstream_preparation' AND agent_id = 'upstream-preparation' AND status = 'pending'",
                    'reject_sql' => "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE id = ? AND review_type = 'upstream_preparation' AND agent_id = 'upstream-preparation' AND status = 'pending'",
                    'field_mapping' => json_encode(['unified_id_template' => 'upstream_preparation:{{id}}', 'id' => 'id', 'title' => 'title',
                        'summary' => 'summary', 'priority' => 'priority', 'details_json' => 'details', 'review_type' => 'review_type', 'agent_id' => 'agent_id',
                        'created_at' => 'created_at', 'expires_at' => 'expires_at'], JSON_THROW_ON_ERROR),
                    'ui_schema' => '{}', 'actions' => '["approve","reject"]', 'batch_enabled' => false, 'actionability_pushover_allowed' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        Cache::forget('syscfg:cli.framework_services');
        Cache::forget('syscfg:ops.upstream_private_preparation_admission_policy');
        Cache::forget('syscfg:ops.upstream_preparation_worker_cycle');
        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        // Preserve all grants, operator overrides, original work and unknown or
        // terminal evidence. A code rollback cannot become retry permission.
    }
};
