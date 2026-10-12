<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Table-owned agent handoff nesting policy. A root agent may create one child by default; trusted runtime context must carry depth and parent identity across queued or synchronous execution.';

    private const DEFAULT_POLICY = [
        'max_depth' => 1,
        'max_sidecar_depth' => 0,
    ];

    private const RUNTIME_CONTEXT_PARAMETER = [
        'type' => 'object',
        'description' => 'Trusted PLOS runtime identity and delegation context; never supplied by the model.',
        'required' => true,
        'x_context_only' => true,
    ];

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $exists = DB::table('system_configs')
                ->where('section', 'agents')
                ->where('config_key', 'delegation_runtime_policy')
                ->exists();
            if (! $exists) {
                DB::table('system_configs')->insert([
                    'section' => 'agents',
                    'config_key' => 'delegation_runtime_policy',
                    'config_value' => json_encode(self::DEFAULT_POLICY, JSON_THROW_ON_ERROR),
                    'data_type' => 'json',
                    'description' => self::DESCRIPTION,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Cache::forget('syscfg:agents.delegation_runtime_policy');
        }

        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $tool = DB::table('agent_tool_registry')
            ->where('name', 'handoff_to_agent')
            ->where('service_class', 'App\\Services\\AgentHandoffService')
            ->first(['id', 'method', 'parameters']);
        if ($tool === null || ! in_array($tool->method, ['handoff', 'handoffFromAgent'], true)) {
            return;
        }

        $parameters = json_decode((string) $tool->parameters, true);
        if (! is_array($parameters)) {
            return;
        }
        $parameters['runtime_context'] = self::RUNTIME_CONTEXT_PARAMETER;

        DB::table('agent_tool_registry')->where('id', $tool->id)->update([
            'method' => 'handoffFromAgent',
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            $tool = DB::table('agent_tool_registry')
                ->where('name', 'handoff_to_agent')
                ->where('service_class', 'App\\Services\\AgentHandoffService')
                ->where('method', 'handoffFromAgent')
                ->first(['id', 'parameters']);
            if ($tool !== null) {
                $parameters = json_decode((string) $tool->parameters, true);
                if (is_array($parameters)
                    && is_array($parameters['runtime_context'] ?? null)
                    && ($parameters['runtime_context']['x_context_only'] ?? false) === true
                    && ($parameters['runtime_context']['description'] ?? null) === self::RUNTIME_CONTEXT_PARAMETER['description']) {
                    unset($parameters['runtime_context']);
                    DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                        'method' => 'handoff',
                        'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'agents')
                ->where('config_key', 'delegation_runtime_policy')
                ->where('config_value', json_encode(self::DEFAULT_POLICY, JSON_THROW_ON_ERROR))
                ->where('description', self::DESCRIPTION)
                ->delete();
            Cache::forget('syscfg:agents.delegation_runtime_policy');
        }
    }
};
