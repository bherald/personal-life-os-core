<?php

use App\Services\UpstreamSourceDriftRuntimePolicyService;
use App\Services\UpstreamSourceDriftService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB = 'whiteroom_monitor_due_report';

    private const BEFORE = 'whiteroom:monitor --format=json --compact --due --limit=25';

    private const AFTER = 'whiteroom:monitor --format=json --compact --prepare';

    private const DESCRIPTION = 'Daily bounded official upstream metadata and backend preparation receipts; no operator reviews or public writes.';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('scheduled_jobs')) {
            return;
        }
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'upstream_source_drift_runtime_policy')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR);
            if (($policy['schema'] ?? null) !== UpstreamSourceDriftRuntimePolicyService::SCHEMA) {
                return;
            }
            $changed = false;
            if (! array_key_exists('response_max_bytes', $policy)) {
                $policy['response_max_bytes'] = 262144;
                $changed = true;
            }
            if (! array_key_exists('preparation', $policy)) {
                $changed = true;
                $policy['preparation'] = ['enabled' => false, 'limit' => 10, 'budget_seconds' => 180,
                    'retry_after_seconds' => 86400, 'max_sources' => 10000,
                    'priority_project_keys' => [], 'owner' => 'operator',
                    'integration_owner' => 'operator', 'deployment_owner' => 'operator',
                    'owning_record' => 'docs/upstream-metadata-preparation.md',
                    'metadata_network_profile' => 'upstream_public_metadata',
                    'next_action' => 'Compare exact changed source/release and lawful reuse terms; prepare bounded benefit, test, source and rollback evidence. Keep incomplete/deferred work backend-only; request final human approval only for a fully prepared packet with exact public target/content where applicable.'];
            }
            if ($changed) {
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            DB::table('system_configs')->insertOrIgnore(['section' => 'ops', 'config_key' => UpstreamSourceDriftService::PREPARATION_STATE_KEY,
                'config_value' => json_encode(['schema' => 'plos.upstream_preparation_state.v1', 'cursor' => null], JSON_THROW_ON_ERROR),
                'data_type' => 'json', 'description' => 'Durable upstream preparation cursor; preserve backend receipts and unfinished work.', 'created_at' => now(), 'updated_at' => now()]);
            if (($policy['preparation']['enabled'] ?? false) !== true) {
                return; // Public registration preserves the existing schedule until explicitly enabled.
            }
            $job = DB::table('scheduled_jobs')->where('name', self::JOB)->where('job_type', 'command')->where('command', self::BEFORE)->lockForUpdate()->first();
            if ($job === null) {
                return;
            }
            $notes = json_decode($job->notes ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($notes)) {
                return;
            }
            $notes['upstream_preparation'] = ['schema' => 'plos.upstream_preparation_schedule.v1', 'operator_actionable' => false,
                'pushover_allowed' => false, 'public_writes' => false, 'writes_backend_receipts' => true];
            $notes['safety']['read_only'] = false;
            $stateRow = DB::table('system_configs')->where('section', 'ops')->where('config_key', UpstreamSourceDriftService::PREPARATION_STATE_KEY)->lockForUpdate()->first();
            $state = json_decode($stateRow->config_value, true, 512, JSON_THROW_ON_ERROR);
            if (($state['schema'] ?? null) !== 'plos.upstream_preparation_state.v1') {
                return;
            }
            $postimage = ['command' => self::AFTER, 'description' => self::DESCRIPTION, 'notification_mode' => 'silent',
                'notes' => json_encode($notes, JSON_THROW_ON_ERROR)];
            $state['schedule_postimage'] = $postimage;
            $state['schedule_preimage'] = ['command' => $job->command, 'description' => $job->description,
                'notification_mode' => $job->notification_mode, 'notes' => $job->notes];
            DB::table('system_configs')->where('id', $stateRow->id)->update(['config_value' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            DB::table('scheduled_jobs')->where('id', $job->id)->where('command', self::BEFORE)->update($postimage + ['updated_at' => now()]);
        });
        Cache::forget('syscfg:ops.upstream_source_drift_runtime_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('scheduled_jobs')) {
            return;
        }
        $row = DB::table('system_configs')->where('section', 'ops')->where('config_key', UpstreamSourceDriftService::PREPARATION_STATE_KEY)->first();
        $state = $row ? json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR) : [];
        if (is_array($state['schedule_preimage'] ?? null) && is_array($state['schedule_postimage'] ?? null)) {
            DB::table('scheduled_jobs')->where('name', self::JOB)->where('command', self::AFTER)
                ->where('description', self::DESCRIPTION)->where('notification_mode', 'silent')
                ->where('notes', $state['schedule_postimage']['notes'])
                ->update($state['schedule_preimage'] + ['updated_at' => now()]);
        }
        // Keep cursor, receipts, deferred work and operator policy overrides.
    }
};
