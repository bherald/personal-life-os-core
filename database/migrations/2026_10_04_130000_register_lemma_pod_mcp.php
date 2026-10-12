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
            $row = (new CliMcpServerDefinitions)->fromConfiguration(['lemma-pod' => config('mcp.servers.lemma-pod')], base_path())[0];
            $row['definition'] = json_encode($row['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! DB::table('mcp_server_definitions')->where('server_name', 'lemma-pod')->exists()) {
                DB::table('mcp_server_definitions')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
            $catalog = json_decode(file_get_contents(base_path('resources/plos/lemma-pod-mcp-tools.json')),
                true, flags: JSON_THROW_ON_ERROR);
            foreach ($catalog as $tool) {
                if (DB::table('agent_tool_registry')->where('name', $tool['name'])->exists()) {
                    continue; // Preserve operator overrides and disabled rows.
                }
                $write = in_array($tool['name'], ['lemma_pod_write_record', 'lemma_pod_write_file',
                    'lemma_pod_get_file_url'], true);
                DB::table('agent_tool_registry')->insert([
                    'name' => $tool['name'], 'service_class' => 'App\\Engine\\MCPRouter', 'method' => 'callTool',
                    'description' => $tool['description'],
                    'parameters' => json_encode($tool['inputSchema'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'returns_description' => 'Result from the selected local Lemma pod under its agent-account permissions.',
                    'permissions' => json_encode([$write ? 'lemma:write' : 'lemma:read'], JSON_THROW_ON_ERROR),
                    'risk_level' => $write ? 'write' : 'read', 'category' => 'lemma',
                    'requires_confirmation' => $write, 'max_calls_per_run' => $write ? 4 : 20,
                    'mcp_server' => 'lemma-pod', 'mcp_tool' => $tool['name'],
                    'enabled' => true, 'source' => 'config', 'allows_private_data' => true,
                    'privacy_class' => 'private_internal', 'availability_status' => 'available',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('lemma-pod-ops');
            if ($skill === null) {
                throw new RuntimeException('Missing Lemma pod coordination skill.');
            }
            app(SkillVersionService::class)->trackVersion('lemma-pod-ops',
                $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
    }

    public function down(): void
    {
        // Preserve registry and review history; operator can disable these entries.
    }
};
