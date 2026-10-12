<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'rag';

    private const KEY = 'relevance_gating_runtime_policy';

    private const DESCRIPTION = 'Table-owned relevance scoring, prompt budget, model role, and filtering policy for RAG relevance gating.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.rag_relevance_gating_runtime_policy.v1',
            'relevance_threshold' => 0.50,
            'max_documents_to_gate' => 12,
            'max_document_chars' => 500,
            'neutral_score' => 0.50,
            'scorer' => [
                'max_tokens' => 100,
                'temperature' => 0.0,
                'model_role' => 'fast',
            ],
            'policy_notes' => [
                'failure_behavior' => 'Missing, malformed, or out-of-range policy skips relevance gating before model dispatch through the RAG fail-open boundary.',
                'override_behavior' => 'A caller may explicitly override only the relevance threshold for one request, including zero to bypass scoring.',
                'privacy' => 'Raw query text, document identity, and model-generated reasons are excluded from logs.',
                'prompt_integrity' => 'Document excerpts are truncated only to the explicit policy cap; prompt compression and precompaction remain disabled.',
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
        return json_encode(
            $this->policy(),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
};
