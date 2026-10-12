<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const INSTANCE_IDS = [
        'cerebras_free',
        'groq_free',
        'deepinfra_free',
        'openrouter_free',
        'gemini_free',
        'mistral_free',
        'sambanova_free',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $rows = DB::table('llm_instances')
            ->whereIn('instance_id', self::INSTANCE_IDS)
            ->get(['id', 'instance_id', 'is_active', 'routability']);

        foreach ($rows as $row) {
            if ((int) $row->is_active !== 0 || $row->routability !== 'bench_only') {
                throw new RuntimeException(
                    "Refusing to retire provider {$row->instance_id}: expected inactive bench_only posture."
                );
            }
        }

        $this->assertNoRuntimePolicyReferences();

        DB::transaction(function () use ($rows): void {
            $rowIds = $rows->pluck('id')->all();

            if ($rowIds !== [] && Schema::hasTable('ollama_models')) {
                DB::table('ollama_models')->whereIn('instance_id', $rowIds)->delete();
            }

            if (Schema::hasTable('oauth_tokens')) {
                DB::table('oauth_tokens')->whereIn('provider', self::INSTANCE_IDS)->delete();
            }

            DB::table('llm_instances')->whereIn('instance_id', self::INSTANCE_IDS)->delete();
        });

        $this->clearCaches();
    }

    public function down(): void
    {
        // Operator-retired provider definitions are intentionally not recreated.
        // Historical request/cascade evidence remains available for audit.
        $this->clearCaches();
    }

    private function assertNoRuntimePolicyReferences(): void
    {
        if (Schema::hasTable('system_configs')) {
            $query = DB::table('system_configs');
            $this->addIdentifierSearch($query, ['config_value']);
            if ($query->exists()) {
                throw new RuntimeException('Refusing provider retirement: system_configs still references a target provider.');
            }
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $columns = array_values(array_filter(
                ['command', 'description', 'notes'],
                static fn (string $column): bool => Schema::hasColumn('scheduled_jobs', $column)
            ));
            if ($columns !== []) {
                $query = DB::table('scheduled_jobs');
                $this->addIdentifierSearch($query, $columns);
                if ($query->exists()) {
                    throw new RuntimeException('Refusing provider retirement: scheduled_jobs still references a target provider.');
                }
            }
        }
    }

    /** @param list<string> $columns */
    private function addIdentifierSearch($query, array $columns): void
    {
        $query->where(function ($outer) use ($columns): void {
            foreach ($columns as $column) {
                foreach (self::INSTANCE_IDS as $instanceId) {
                    $outer->orWhere($column, 'like', "%{$instanceId}%");
                }
            }
        });
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'external_api_providers',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
