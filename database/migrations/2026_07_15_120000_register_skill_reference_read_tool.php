<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $parameters = [
            'type' => 'object',
            'properties' => [
                'reference' => [
                    'type' => 'string',
                    'description' => 'One Markdown filename directly under the active skill bundle references directory.',
                ],
                'agent_id' => [
                    'type' => 'string',
                    'x_context_only' => true,
                    'description' => 'Injected from trusted runtime context and never accepted from model input.',
                ],
            ],
            'required' => ['reference', 'agent_id'],
            'additionalProperties' => false,
        ];

        $values = [
            'service_class' => 'App\\Services\\AgentSkillReferenceService',
            'method' => 'read',
            'description' => 'Load one explicitly named progressive-disclosure Markdown reference from the active agent skill bundle. The runtime binds the active agent identity; paths, nested references, symlinks, and oversized files fail closed.',
            'parameters' => json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'returns_description' => 'Reference filename, bounded Markdown content, byte count, and SHA-256 digest.',
            'permissions' => json_encode(['system:read'], JSON_UNESCAPED_SLASHES),
            'risk_level' => 'read',
            'category' => 'skill_context',
            'requires_confirmation' => 0,
            'max_calls_per_run' => 4,
            'max_tokens_per_call' => 8000,
            'enabled' => 1,
            'source' => 'config',
            'notes' => 'Agent Skills progressive-disclosure reader; one-level active-bundle Markdown only.',
            'updated_at' => now(),
        ];

        foreach ([
            'max_result_bytes' => 32_000,
            'availability_status' => 'available',
            'schema_generation' => 1,
            'privacy_class' => 'internal_private',
            'allows_private_data' => 1,
        ] as $column => $value) {
            if (Schema::hasColumn('agent_tool_registry', $column)) {
                $values[$column] = $value;
            }
        }

        DB::table('agent_tool_registry')->updateOrInsert(['name' => 'skill_reference_read'], $values);
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            DB::table('agent_tool_registry')->where('name', 'skill_reference_read')->delete();
        }
    }
};
