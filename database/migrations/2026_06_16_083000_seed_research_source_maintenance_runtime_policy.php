<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POLICY_SOURCE = 'system_configs.research.source_maintenance_runtime_policy';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'enabled' => true,
            'run_healing_default' => true,
            'run_refresh_default' => true,
            'run_discover_default' => true,
            'run_optimize_default' => true,
            'send_notification_default' => false,
            'healthy_threshold' => 0,
            'degraded_threshold' => 2,
            'failed_threshold' => 5,
            'healing_source_limit' => 50,
            'healing_connect_timeout_seconds' => 5,
            'healing_request_timeout_seconds' => 10,
            'healing_redirect_max' => 5,
            'rule_low_success_rate' => 0.30,
            'rule_high_success_rate' => 0.80,
            'rule_tld_min_total' => 5,
            'rule_category_min_total' => 3,
            'rule_unused_disable_days' => 30,
            'tld_analysis_min_sources' => 3,
            'category_domain_analysis_min_observations' => 3,
            'category_domain_analysis_limit' => 50,
            'category_stale_days' => 30,
            'category_critical_min_active' => 3,
            'category_critical_min_search_url' => 2,
            'category_warning_min_active' => 5,
            'category_warning_min_search_url' => 3,
            'category_stale_ratio' => 0.5,
            'category_list' => [
                'academic',
                'finance',
                'genealogy',
                'general',
                'government',
                'health',
                'legal',
                'medical',
                'news',
                'science',
                'technology',
            ],
            'category_refresh_limit' => 20,
            'category_refresh_connect_timeout_seconds' => 5,
            'category_refresh_request_timeout_seconds' => 15,
            'category_refresh_sleep_microseconds' => 300000,
            'deactivation_high_trust_min' => 8.0,
            'deactivation_medium_trust_min' => 6.0,
            'deactivation_high_trust_failure_threshold' => 10,
            'deactivation_medium_trust_failure_threshold' => 7,
            'deactivation_default_failure_threshold' => 5,
            'deactivation_failure_success_multiplier' => 2.0,
            'report_failing_limit' => 20,
            'report_top_performing_limit' => 20,
            'report_top_performing_min_observations' => 5,
            'report_min_success_rate_pct' => 70.0,
            'report_degraded_recommendation_threshold' => 10,
            'report_unused_rule_recommendation_threshold' => 5,
            'report_min_avg_trust' => 0.5,
            'suggest_success_min_count' => 5,
            'suggest_success_min_rate' => 0.7,
            'suggest_successful_domain_limit' => 20,
            'suggest_common_tld_limit' => 5,
            'suggest_common_keyword_limit' => 10,
            'suggest_keyword_term_limit' => 3,
            'suggest_keyword_terms_per_base' => 2,
            'policy_notes' => [
                'scope' => 'Runtime policy for ResearchMaintenanceNode and SourceOptimizationService source maintenance operations.',
                'stage_defaults' => 'Workflow node config remains the per-run override for run_healing, run_refresh, run_discover, run_optimize, category, and send_notification.',
                'service_limits' => 'Source limits, request timeouts, stale windows, category thresholds, and deactivation thresholds are table-owned.',
                'scheduled_job_owner' => 'Cadence, timeout_minutes, max_parallel, and overlap behavior remain owned by scheduled_jobs.',
                'robots_policy' => 'Robots bypass behavior remains owned by config(scraping.bypass_robots_txt).',
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->seedSystemConfig();
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $this->annotateScheduledJob();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'research')
                ->where('config_key', 'source_maintenance_runtime_policy')
                ->delete();
        }
    }

    private function seedSystemConfig(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'research')
            ->where('config_key', 'source_maintenance_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'research', 'config_key' => 'source_maintenance_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for research source maintenance, healing, refresh, stale windows, and deactivation thresholds.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function annotateScheduledJob(): void
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', 'workflow_research_source_maintenance')
            ->first(['id', 'notes']);

        if ($row === null) {
            return;
        }

        $notes = json_decode((string) ($row->notes ?? ''), true);
        if (! is_array($notes)) {
            $notes = [
                'summary' => trim((string) ($row->notes ?? '')),
            ];
        }

        $notes['policy_source'] = self::POLICY_SOURCE;
        $runtime = $notes['runtime'] ?? [];
        if (! is_array($runtime)) {
            $runtime = [];
        }

        $notes['runtime'] = array_replace($runtime, [
            'policy_source' => self::POLICY_SOURCE,
            'runtime_mode' => 'maintenance',
            'workload_family' => 'research',
            'resource_profile' => 'network',
            'backlog_metric' => 'research_source_health',
        ]);

        DB::table('scheduled_jobs')
            ->where('id', $row->id)
            ->update([
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'runtime_mode' => 'maintenance',
                'workload_family' => 'research',
                'resource_profile' => 'network',
                'backlog_metric' => 'research_source_health',
                'notification_mode' => 'digest',
                'updated_at' => now(),
            ]);
    }
};
