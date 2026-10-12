<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'rag';

    private const KEY = 'reranker_sidecar_runtime_policy';

    private const DESCRIPTION = 'Table-owned health, startup, request, and CPU inference tuning for the evaluation-only reranker sidecar.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.rag_reranker_sidecar_runtime_policy.v1',
            'health' => [
                'connect_timeout_seconds' => 1,
                'request_timeout_seconds' => 3,
            ],
            'launcher_timeout_seconds' => 10,
            'startup_timeout_seconds' => 240,
            'startup_poll_seconds' => 2,
            'shutdown' => [
                'connect_timeout_seconds' => 1,
                'request_timeout_seconds' => 5,
            ],
            'rerank' => [
                'connect_timeout_seconds' => 2,
                'request_timeout_seconds' => 180,
            ],
            'inference' => [
                'max_length' => 1024,
                'batch_size' => 4,
                'threads' => 6,
            ],
            'policy_notes' => [
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops sidecar health, lifecycle, and rerank operations before HTTP or process dispatch.',
                'routing' => 'This policy cannot start a schedule, promote the challenger, alter production RAG routing, or change model and revision identity.',
                'hardware' => 'Inference batch, sequence length, and CPU thread count remain operator-tunable without changing the pinned artifact.',
                'prompt_integrity' => 'The sidecar receives the original query, instruction, and bounded documents; prompt compression and precompaction remain disabled.',
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
