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
            'temperature' => 0.0,
            'max_tokens' => 500,
            'timeout_seconds' => 45,
            'tool_result_max_chars' => 6000,
            'response_temperature' => 0.1,
            'direct_response_temperature' => 0.1,
            'policy_notes' => [
                'temperature' => 'Fallback deterministic temperature for OllamaToolCaller tool selection when config/mcp.php does not provide a value.',
                'max_tokens' => 'Fallback tool-selection max token budget when config/mcp.php does not provide a value.',
                'timeout_seconds' => 'Fallback tool-calling timeout when config/mcp.php does not provide a value.',
                'tool_result_max_chars' => 'Fallback maximum external-data envelope size for MCP tool results.',
                'response_temperature' => 'Fallback temperature used when formatting a final response from a tool result.',
                'direct_response_temperature' => 'Fallback temperature used for direct AI responses when no tool is selected.',
                'ownership' => 'OllamaToolCaller runtime defaults are table-owned here; config/mcp.php values remain higher-priority deployment overrides.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ai')
            ->where('config_key', 'ollama_tool_caller_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ai', 'config_key' => 'ollama_tool_caller_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for OllamaToolCaller defaults.',
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
            ->where('section', 'ai')
            ->where('config_key', 'ollama_tool_caller_runtime_policy')
            ->delete();
    }
};
