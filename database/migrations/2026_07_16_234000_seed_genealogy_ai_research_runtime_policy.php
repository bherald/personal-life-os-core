<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'genealogy';

    private const KEY = 'ai_research_runtime_policy';

    private const DESCRIPTION = 'Table-owned model-role and cache policy for interactive Genealogy AI research operations.';

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.genealogy_ai_research_runtime_policy.v1',
            'cache_ttl_seconds' => 86400,
            'operation_model_roles' => [
                'research_person' => 'quality',
                'brick_wall' => 'quality',
                'source_evaluation' => 'quality',
                'relationship_analysis' => 'quality',
                'proposal_extraction' => 'quality',
            ],
            'policy_notes' => [
                'cache_ttl_seconds' => 'Shared result-cache lifetime for the four cacheable interactive research operations.',
                'operation_model_roles' => 'AIService resolves each operation through the table-selected model role and current provider inventory.',
                'failure_behavior' => 'Missing, malformed, incomplete, or unsupported policy stops before AI dispatch.',
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
