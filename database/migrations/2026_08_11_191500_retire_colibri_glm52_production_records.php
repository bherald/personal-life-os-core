<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'colibri_87';

    private const MODEL = 'glm-5.2-colibri';

    private const PROFILE = 'deep_offline_colibri';

    /** @var list<string> */
    private const POLICY_KEYS = [
        'colibri_research_canary_policy',
        'colibri_local_research_canary_policy',
    ];

    /** @var list<string> */
    private const TOOL_NAMES = [
        'colibri_deep_research',
        'colibri_local_research',
    ];

    public function up(): void
    {
        $instanceRowId = Schema::hasTable('llm_instances')
            ? DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->value('id')
            : null;

        DB::transaction(function () use ($instanceRowId): void {
            $this->deleteRequestLogs($instanceRowId);
            $this->deleteCascadeLogs();
            $this->deleteModelRegistryRows($instanceRowId);
            $this->deleteModelProfiles();
            $this->deleteResearchPolicies();
            $this->deleteResearchTools();

            if (Schema::hasTable('llm_instances')) {
                DB::table('llm_instances')
                    ->where('instance_id', self::INSTANCE_ID)
                    ->delete();
            }
        });

        $this->clearCaches();
    }

    public function down(): void
    {
        // This operator-directed data retirement is intentionally not recreated
        // by rollback. Restore only from the separately retained production
        // receipt after an explicit operator decision.
        $this->clearCaches();
    }

    private function deleteRequestLogs(mixed $instanceRowId): void
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        DB::table('llm_model_request_logs')
            ->where(function ($query) use ($instanceRowId): void {
                $query->where('instance_id', self::INSTANCE_ID)
                    ->orWhere('model_name', self::MODEL)
                    ->orWhere('model_name', 'like', '%glm-5.2%');

                if ($instanceRowId !== null) {
                    $query->orWhere('llm_instance_row_id', $instanceRowId);
                }
            })
            ->delete();
    }

    private function deleteCascadeLogs(): void
    {
        if (! Schema::hasTable('llm_cascade_log')) {
            return;
        }

        DB::table('llm_cascade_log')
            ->where(function ($query): void {
                $query->where('initial_provider', self::INSTANCE_ID)
                    ->orWhere('escalated_provider', self::INSTANCE_ID)
                    ->orWhere('initial_model', self::MODEL)
                    ->orWhere('escalated_model', self::MODEL)
                    ->orWhere('initial_model', 'like', '%glm-5.2%')
                    ->orWhere('escalated_model', 'like', '%glm-5.2%');
            })
            ->delete();
    }

    private function deleteModelRegistryRows(mixed $instanceRowId): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        DB::table('ollama_models')
            ->where(function ($query) use ($instanceRowId): void {
                $query->where('model_name', self::MODEL)
                    ->orWhere('model_name', 'like', '%glm-5.2%')
                    ->orWhere('profile', self::PROFILE);

                if ($instanceRowId !== null) {
                    $query->orWhere('instance_id', $instanceRowId);
                }
            })
            ->delete();
    }

    private function deleteModelProfiles(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        DB::table('llm_model_profiles')
            ->where(function ($query): void {
                $query->where('profile_name', self::PROFILE)
                    ->orWhere('model_name', self::MODEL)
                    ->orWhere('model_name', 'like', '%glm-5.2%');

                if (Schema::hasColumn('llm_model_profiles', 'metadata')) {
                    $query->orWhere('metadata', 'like', '%colibri_87%');
                }
            })
            ->delete();
    }

    private function deleteResearchPolicies(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->whereIn('config_key', self::POLICY_KEYS)
            ->orWhere('config_value', 'like', '%colibri_87%')
            ->orWhere('config_value', 'like', '%glm-5.2%')
            ->delete();
    }

    private function deleteResearchTools(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        DB::table('agent_tool_registry')
            ->whereIn('name', self::TOOL_NAMES)
            ->delete();
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'external_api_providers',
            'llm_model_profiles',
            'ollama_available_models',
            'ollama_vetted_models',
            'agent_tool_registry',
            'system_config_ai_colibri_research_canary_policy',
            'system_config_ai_colibri_local_research_canary_policy',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
