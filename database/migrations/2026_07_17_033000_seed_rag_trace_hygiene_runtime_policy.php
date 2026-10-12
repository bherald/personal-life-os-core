<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'rag';

    private const KEY = 'trace_hygiene_runtime_policy';

    private const DESCRIPTION = 'Table-owned limits for guarded legacy RAG trace privacy hygiene.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.rag_trace_hygiene_runtime_policy.v1',
            'default_limit' => 50000,
            'max_rows' => 50000,
            'batch_size' => 500,
            'policy_notes' => [
                'scope' => 'Manual privacy maintenance only; this policy does not schedule or enable execution.',
                'write_gate' => 'Mutation still requires both --execute and --confirm after reviewing a dry-run.',
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops before querying or updating PostgreSQL trace rows.',
                'privacy' => 'Selected legacy query text is replaced with an installation-keyed HMAC fingerprint and fixed content-free metadata.',
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
