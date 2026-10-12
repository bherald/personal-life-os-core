<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\UnifiedCli\CliMcpServerDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = (new CliMcpServerDefinitions)->fromConfiguration(['hister' => config('mcp.servers.hister')], base_path())[0];
            $row['definition'] = json_encode($row['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! DB::table('mcp_server_definitions')->where('server_name', 'hister')->exists()) {
                DB::table('mcp_server_definitions')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
            foreach (config('hister.tools') as $tool) {
                $name = 'hister_'.$tool['name'];
                if (DB::table('agent_tool_registry')->where('name', $name)->exists()) {
                    continue; // Preserve operator changes, including disabling a tool.
                }
                DB::table('agent_tool_registry')->insert([
                    'name' => $name, 'service_class' => 'App\\Engine\\MCPRouter', 'method' => 'callTool',
                    'description' => $tool['description'], 'parameters' => json_encode($tool['inputSchema'], JSON_THROW_ON_ERROR),
                    'returns_description' => 'Local indexed reference evidence or an unavailable result; use existing permitted local search on failure.',
                    'permissions' => json_encode(['system:read']), 'risk_level' => 'read', 'category' => 'system',
                    'requires_confirmation' => false, 'max_calls_per_run' => 20, 'mcp_server' => 'hister', 'mcp_tool' => $tool['name'],
                    'enabled' => true, 'source' => 'config', 'allows_private_data' => true, 'privacy_class' => 'private_internal',
                    'availability_status' => 'available', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (['offline-assistant', 'ai-ops'] as $name) {
                $skill = app(SkillLoaderService::class)->loadSkillSnapshot($name);
                if ($skill === null) {
                    throw new RuntimeException('Missing Hister skill: '.$name);
                }
                app(SkillVersionService::class)->trackVersion($name, $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
        });
    }

    public function down(): void
    {
        // Preserve registry history and operator choices. Disable the server/tools to roll back.
    }
};
