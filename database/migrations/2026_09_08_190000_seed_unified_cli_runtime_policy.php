<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore($this->definition() + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $definition = $this->definition();
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->lockForUpdate()->first();
            $actual = $row === null ? [] : json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $expected = json_decode($definition['config_value'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($actual)) {
                return;
            }
            ksort($actual);
            ksort($expected);
            if ($row !== null && $row->data_type === $definition['data_type']
                && $row->description === $definition['description']
                && $actual === $expected) {
                DB::table('system_configs')->where('id', $row->id)->delete();
            }
        });
    }

    private function definition(): array
    {
        return ['section' => 'cli', 'config_key' => 'runtime_policy', 'data_type' => 'json',
            'config_value' => json_encode(['max_iterations' => 10, 'max_tool_calls' => 40, 'max_output_tokens' => 1024], JSON_THROW_ON_ERROR),
            'description' => 'Unified CLI turn limits. Existing instance/role output settings take precedence. Session overrides and optional saved defaults use the normal PLOS settings mechanism. Limits never authorize truncation, automatic compaction, fallback or action replay.'];
    }
};
