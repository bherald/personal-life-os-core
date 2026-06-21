<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'timeout_seconds' => 60,
            'log_calls' => true,
            'cache_catalog_ttl_seconds' => 3600,
            'tool_timeouts_seconds' => [
                'puppeteer.puppeteer_navigate' => 60,
                'puppeteer.puppeteer_evaluate' => 45,
                'puppeteer.puppeteer_screenshot' => 45,
                'puppeteer.*' => 30,
                'web-research.*' => 45,
                'prompt-compressor.compress_prompt' => 120,
                'prompt-compressor.compress_file' => 120,
                'prompt-compressor.compress_diff' => 120,
                'prompt-compressor.context_retrieve' => 120,
                'prompt-compressor.*' => 30,
            ],
            'policy_notes' => [
                'timeout_seconds' => 'Default MCP tool-call timeout when no exact or server-wildcard override matches.',
                'log_calls' => 'Whether MCPRouter logs tool call metadata.',
                'cache_catalog_ttl_seconds' => 'Cache TTL for the MCP tool catalog.',
                'tool_timeouts_seconds' => 'Exact server.tool or server.* timeout overrides for external MCP tools.',
                'ownership' => 'External MCP process timing is table-owned here; scheduled cadence and worker parallelism remain owned by scheduled_jobs.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        foreach (['tool_timeouts_seconds', 'policy_notes'] as $nestedKey) {
            $existingNested = $existing[$nestedKey] ?? null;
            $policy[$nestedKey] = is_array($existingNested)
                ? array_replace($defaultPolicy[$nestedKey], $existingNested)
                : $defaultPolicy[$nestedKey];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'mcp', 'config_key' => 'router_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for MCPRouter tool timeouts, catalog cache, and logging.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->delete();
    }
};
