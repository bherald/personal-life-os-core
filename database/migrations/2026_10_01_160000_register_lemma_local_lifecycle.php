<?php

use App\Services\UnifiedCli\CliMcpServerDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $server = config('mcp.servers.lemma-local');
            $row = (new CliMcpServerDefinitions)->fromConfiguration(['lemma-local' => $server], base_path())[0];
            $row['definition'] = json_encode($row['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! DB::table('mcp_server_definitions')->where('server_name', 'lemma-local')->exists()) {
                DB::table('mcp_server_definitions')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }

            foreach (config('lemma_local.tools') as $tool) {
                $name = 'lemma_local_'.substr($tool['name'], strlen('lemma_'));
                if (DB::table('agent_tool_registry')->where('name', $name)->exists()) {
                    continue; // Preserve operator choices, including a disabled tool.
                }
                $read = $tool['name'] === 'lemma_status';
                DB::table('agent_tool_registry')->insert([
                    'name' => $name, 'service_class' => 'App\\Engine\\MCPRouter', 'method' => 'callTool',
                    'description' => $tool['description'],
                    'parameters' => json_encode($tool['inputSchema'], JSON_THROW_ON_ERROR),
                    'returns_description' => 'Current local Lemma version, API readiness, and container states.',
                    'permissions' => json_encode([$read ? 'system:read' : 'system:write'], JSON_THROW_ON_ERROR),
                    'risk_level' => $read ? 'read' : 'write', 'category' => 'system',
                    'requires_confirmation' => ! $read, 'max_calls_per_run' => 2,
                    'mcp_server' => 'lemma-local', 'mcp_tool' => $tool['name'],
                    'enabled' => true, 'source' => 'config',
                    'allows_private_data' => false, 'privacy_class' => 'private_internal',
                    'availability_status' => 'available',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep review history and operator choices; disable these rows to roll back.
    }
};
