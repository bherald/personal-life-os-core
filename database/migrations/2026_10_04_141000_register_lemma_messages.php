<?php

use App\Engine\MCPRouter;
use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $router = new MCPRouter;
            $method = new ReflectionMethod($router, 'discoverInternalTools');
            foreach ($method->invoke($router, 'lemma-pod', []) as $tool) {
                if (! str_starts_with($tool['name'], 'lemma_message_')
                    || DB::table('agent_tool_registry')->where('name', $tool['name'])->exists()) {
                    continue;
                }
                $write = in_array($tool['name'], ['lemma_message_send', 'lemma_message_read'], true);
                DB::table('agent_tool_registry')->insert([
                    'name' => $tool['name'], 'service_class' => 'App\\Engine\\MCPRouter', 'method' => 'callTool',
                    'description' => $tool['description'],
                    'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'returns_description' => 'Session-addressed private Lemma message result.',
                    'permissions' => json_encode(['lemma:message'], JSON_THROW_ON_ERROR),
                    'risk_level' => $write ? 'write' : 'read', 'category' => 'lemma',
                    'requires_confirmation' => false, 'max_calls_per_run' => $write ? 10 : 20,
                    'mcp_server' => 'lemma-pod', 'mcp_tool' => $tool['name'],
                    'enabled' => true, 'source' => 'config', 'allows_private_data' => true,
                    'privacy_class' => 'private_internal', 'availability_status' => 'available',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (['offline-assistant', 'lemma-pod-ops'] as $name) {
                $skill = app(SkillLoaderService::class)->loadSkillSnapshot($name);
                if ($skill === null) {
                    throw new RuntimeException('Missing PLOS skill: '.$name);
                }
                app(SkillVersionService::class)->trackVersion($name,
                    $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
        });
    }

    public function down(): void
    {
        // Preserve messages, operator decisions and active skill versions.
    }
};
