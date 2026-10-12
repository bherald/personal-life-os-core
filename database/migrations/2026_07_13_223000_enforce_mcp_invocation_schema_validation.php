<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : [];
        $policy = is_array($policy) ? $policy : [];

        $policy += [
            'validate_tool_arguments' => true,
            'reject_unknown_tool_arguments' => true,
        ];
        $notes = is_array($policy['policy_notes'] ?? null) ? $policy['policy_notes'] : [];
        $policy['policy_notes'] = $notes + [
            'validate_tool_arguments' => 'Validate every admitted invocation against its advertised JSON Schema before handler dispatch.',
            'reject_unknown_tool_arguments' => 'Treat undeclared root arguments as out-of-scope and reject them before dispatch.',
            'argument_logging' => 'Log argument names and a digest only; never log argument values.',
        ];

        $values = [
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
            'data_type' => 'json',
            'description' => 'Runtime policy for MCPRouter timeouts, catalog caching, logging, and pre-dispatch invocation validation.',
            'updated_at' => now(),
        ];
        if ($row === null) {
            $values['created_at'] = now();
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'mcp', 'config_key' => 'router_runtime_policy'],
            $values
        );

        Cache::forget('syscfg:mcp.router_runtime_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        unset($policy['validate_tool_arguments'], $policy['reject_unknown_tool_arguments']);
        foreach (['validate_tool_arguments', 'reject_unknown_tool_arguments', 'argument_logging'] as $key) {
            unset($policy['policy_notes'][$key]);
        }

        DB::table('system_configs')
            ->where('section', 'mcp')
            ->where('config_key', 'router_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        Cache::forget('syscfg:mcp.router_runtime_policy');
    }
};
