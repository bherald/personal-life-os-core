<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'colibri_87';

    public function up(): void
    {
        $this->update(true);
    }

    public function down(): void
    {
        $this->update(false);
    }

    private function update(bool $enable): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $instance = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($instance === null) {
            return;
        }
        $config = json_decode((string) ($instance->config ?? ''), true);
        $config = is_array($config) ? $config : [];
        $policy = is_array($config['stream_policy'] ?? null) ? $config['stream_policy'] : [];
        if ($enable) {
            $policy['ignore_leading_stop_sequences'] = true;
        } elseif (($policy['ignore_leading_stop_sequences'] ?? null) === true) {
            unset($policy['ignore_leading_stop_sequences']);
        }
        $config['stream_policy'] = $policy;
        DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->update([
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }
};
