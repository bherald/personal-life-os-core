<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'rag';

    private const KEY = 'grounding_verifier_runtime_policy';

    private const DESCRIPTION = 'Table-owned claim, evidence, verdict, prompt-budget, and model-routing policy for RAG grounding verification.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.rag_grounding_verifier_runtime_policy.v1',
            'max_claims' => 6,
            'max_source_documents' => 3,
            'response_max_chars' => 2000,
            'document_max_chars' => 700,
            'minimum_claim_chars' => 10,
            'grounded_threshold' => 0.75,
            'partial_threshold' => 0.40,
            'neutral_score' => 0.50,
            'claim_extractor' => [
                'max_tokens' => 400,
                'temperature' => 0.10,
                'model_role' => 'fast',
            ],
            'nli' => [
                'max_tokens' => 120,
                'temperature' => 0.0,
                'model_role' => 'fast',
            ],
            'policy_notes' => [
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops grounding verification before model dispatch.',
                'privacy' => 'Raw model errors and malformed model responses are excluded from logs.',
                'prompt_integrity' => 'Response and evidence excerpts are truncated only to explicit policy caps; prompt compression and precompaction remain disabled.',
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
