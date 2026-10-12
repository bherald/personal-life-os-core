<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ops';

    private const KEY = 'upstream_source_drift_runtime_policy';

    private const DESCRIPTION = 'Table-owned selection and request bounds for read-only upstream source drift checks.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.ops_upstream_source_drift_runtime_policy.v1',
            'default_limit' => 20,
            'max_limit' => 500,
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 15,
            'max_request_timeout_seconds' => 60,
            'policy_notes' => [
                'selection' => 'Omitted --limit uses default_limit; explicit limits may not exceed max_limit.',
                'coverage' => 'Use an explicit limit with --require-complete when a full selected source set must be checked.',
                'timeouts' => 'Operators may lower or raise per-request timeout only within the validated maximum.',
                'failure_behavior' => 'Missing, malformed, or incoherent policy stops before source selection or network access.',
                'static_config' => 'API protocol version and User-Agent remain deployment identity, not runtime throughput policy.',
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
