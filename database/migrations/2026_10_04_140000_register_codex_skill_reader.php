<?php

use App\Engine\MCPRouter;
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
            $row = (new CliMcpServerDefinitions)->fromConfiguration(['codex-skills' => config('mcp.servers.codex-skills')], base_path())[0];
            $row['definition'] = json_encode($row['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! DB::table('mcp_server_definitions')->where('server_name', 'codex-skills')->exists()) {
                DB::table('mcp_server_definitions')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
            $router = new MCPRouter;
            $method = new ReflectionMethod($router, 'discoverInternalTools');
            foreach ($method->invoke($router, 'codex-skills', []) as $tool) {
                if (DB::table('agent_tool_registry')->where('name', $tool['name'])->exists()) {
                    continue;
                }
                DB::table('agent_tool_registry')->insert([
                    'name' => $tool['name'], 'service_class' => 'App\\Engine\\MCPRouter', 'method' => 'callTool',
                    'description' => $tool['description'],
                    'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'returns_description' => 'Installed Codex skill instructions or support text.',
                    'permissions' => json_encode(['codex:skills:read'], JSON_THROW_ON_ERROR),
                    'risk_level' => 'read', 'category' => 'skills', 'requires_confirmation' => false,
                    'max_calls_per_run' => 30, 'mcp_server' => 'codex-skills', 'mcp_tool' => $tool['name'],
                    'enabled' => true, 'source' => 'config', 'allows_private_data' => true,
                    'privacy_class' => 'private_internal', 'availability_status' => 'available',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('offline-assistant');
            if ($skill === null) {
                throw new RuntimeException('Missing offline assistant skill.');
            }
            app(SkillVersionService::class)->trackVersion('offline-assistant',
                $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
    }

    public function down(): void
    {
        // Preserve operator-owned registry and skill history.
    }
};
