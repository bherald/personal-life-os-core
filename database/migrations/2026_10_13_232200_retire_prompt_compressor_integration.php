<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retain existing audit/receipt references; these tombstones cannot launch a server.
        foreach (['agent_tool_registry' => 'mcp_server', 'mcp_server_definitions' => 'server_name'] as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'enabled')) {
                continue;
            }
            $updates = ['enabled' => 0];
            if (Schema::hasColumn($table, 'availability_status')) {
                $updates['availability_status'] = 'disabled';
            }
            if (Schema::hasColumn($table, 'last_error')) {
                $updates['last_error'] = 'Prompt compressor retired by operator; original prompt content must be preserved.';
            }
            if (Schema::hasColumn($table, 'updated_at')) {
                $updates['updated_at'] = now();
            }
            DB::table($table)->where($column, 'prompt-compressor')->update($updates);
        }

        if (Schema::hasTable('system_configs')) {
            $row = DB::table('system_configs')->where('section', 'mcp')->where('config_key', 'router_runtime_policy')->first();
            $policy = $row ? json_decode((string) $row->config_value, true) : null;
            if (is_array($policy)) {
                foreach (array_keys($policy['tool_timeouts_seconds'] ?? []) as $name) {
                    if (str_starts_with((string) $name, 'prompt-compressor.')) {
                        unset($policy['tool_timeouts_seconds'][$name]);
                    }
                }
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        }
        foreach (['syscfg:mcp.router_runtime_policy', 'mcp:tool_catalog', 'mcp:tool_catalog:static', 'mcp:tool_catalog:dynamic'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Rolling source back must not re-enable an operator-retired lossy component.
    }
};
