<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'rag';

    private const KEY = 'source_identity_dedup_runtime_policy';

    private const DESCRIPTION = 'Table-owned batch and lock bounds for exact-content RAG source-identity consolidation.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.rag_source_identity_dedup_runtime_policy.v1',
            'preview_default_groups' => 25,
            'max_groups_per_run' => 500,
            'lock_timeout_seconds' => 10,
            'policy_notes' => [
                'scope' => 'Only file_catalog/file_registry groups with complete identical hashes and exact stored content are eligible.',
                'canonical' => 'Keep newest updated_at then highest id and repoint dependencies before deletion.',
                'execution' => 'Execution requires --execute, --confirm, and an explicit positive --limit.',
                'divergent_versions' => 'Normalized-hash-only, different-hash, and missing-hash groups remain untouched.',
                'failure_behavior' => 'Missing or malformed policy fails before PostgreSQL selection.',
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
