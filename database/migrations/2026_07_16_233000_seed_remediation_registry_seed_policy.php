<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ops';

    private const KEY = 'remediation_registry_seed_policy';

    private const DESCRIPTION = 'Table-owned remediation action definitions used only when explicitly seeding the remediation registry.';

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.remediation_registry_seed_policy.v1',
            'actions' => [
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
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => self::SECTION,
                'config_key' => self::KEY,
                'config_value' => $this->encodedPolicy(),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->where('config_value', $this->encodedPolicy())
            ->where('description', self::DESCRIPTION)
            ->delete();
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    private function encodedPolicy(): string
    {
        return json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
