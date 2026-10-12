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
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'compaction_policy')->lockForUpdate()->first();
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
        return ['section' => 'cli', 'config_key' => 'compaction_policy', 'data_type' => 'json',
            'config_value' => json_encode(['enabled' => true, 'confirmation_ttl_seconds' => 900, 'warning_percent' => 90, 'max_requests' => 16,
                'prompt' => 'The operator explicitly requests a context summary of this conversation. Summarize the task, decisions, constraints, exact paths and identifiers, completed actions and results, outstanding work and uncertainties. Preserve distinctions between verified facts and assumptions. Do not execute tools, continue the task, invent results or replay an earlier action. Return only the summary. Full original messages and artifacts remain saved separately.'], JSON_THROW_ON_ERROR),
            'description' => 'Operator-confirmed CLI compaction only. The selected PLOS model produces a context summary while complete originals and instructions remain saved. Session overrides and optional saved defaults never grant automatic compaction or action replay. Global prompt-integrity policy is unchanged.'];
    }
};
