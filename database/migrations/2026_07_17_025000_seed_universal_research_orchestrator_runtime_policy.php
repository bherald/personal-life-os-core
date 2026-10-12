<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'research';

    private const KEY = 'universal_orchestrator_runtime_policy';

    private const DESCRIPTION = 'Table-owned gathering, extraction, synthesis, and enhanced-knowledge budgets for universal research missions.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.research_universal_orchestrator_runtime_policy.v1',
            'gather' => [
                'max_articles_per_source' => 3,
                'search_timeout_seconds' => 20,
                'article_timeout_seconds' => 15,
                'source_delay_ms' => 500,
                'article_delay_ms' => 300,
                'min_link_text_chars' => 10,
                'min_article_content_chars' => 500,
            ],
            'extraction' => [
                'min_content_chars' => 100,
                'max_content_chars' => 5000,
                'max_facts_per_source' => 5,
                'max_tokens' => 1000,
            ],
            'synthesis' => [
                'max_facts' => 30,
                'max_sources' => 10,
                'max_words' => 500,
                'max_tokens' => 3000,
            ],
            'enhanced_knowledge' => [
                'max_existing_facts' => 10,
                'max_facts' => 15,
                'max_tokens' => 2500,
            ],
            'policy_notes' => [
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops mission execution before status mutation, scraping, or model dispatch.',
                'mission_ownership' => 'Mission rows continue to own total time, source count, depth, verification level, and auto-index review behavior.',
                'privacy' => 'Research queries, source URLs, model errors, and provider attempts are excluded from touched orchestration logs.',
                'prompt_integrity' => 'Explicit character and token budgets bound prompts; prompt compression and precompaction remain disabled.',
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
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
};
