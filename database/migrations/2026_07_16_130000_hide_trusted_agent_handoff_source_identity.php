<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONTEXT_ALIAS = 'agent_id';

    private const LEGACY_DESCRIPTION = 'Your own agent ID (the agent initiating the handoff)';

    private const TRUSTED_DESCRIPTION = 'Trusted PLOS runtime agent identity; never supplied by the model.';

    public function up(): void
    {
        $this->updateSourceIdentity(true);
    }

    public function down(): void
    {
        $this->updateSourceIdentity(false);
    }

    private function updateSourceIdentity(bool $hideFromModel): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $tool = DB::table('agent_tool_registry')
            ->where('name', 'handoff_to_agent')
            ->where('service_class', 'App\\Services\\AgentHandoffService')
            ->where('method', 'handoffFromAgent')
            ->first(['id', 'parameters']);
        if ($tool === null) {
            return;
        }

        $parameters = json_decode((string) $tool->parameters, true);
        if (! is_array($parameters)) {
            return;
        }

        $usesJsonSchema = ($parameters['type'] ?? null) === 'object'
            && is_array($parameters['properties'] ?? null);
        $definitions = $usesJsonSchema ? $parameters['properties'] : $parameters;
        $source = $definitions['source_agent_id'] ?? null;
        if (! is_array($source)) {
            return;
        }

        if ($hideFromModel) {
            $source['x_context_only'] = true;
            $source['x_context_alias'] = self::CONTEXT_ALIAS;
            if (($source['description'] ?? null) === self::LEGACY_DESCRIPTION) {
                $source['description'] = self::TRUSTED_DESCRIPTION;
            }
        } elseif (($source['x_context_alias'] ?? null) === self::CONTEXT_ALIAS) {
            unset($source['x_context_only'], $source['x_context_alias']);
            if (($source['description'] ?? null) === self::TRUSTED_DESCRIPTION) {
                $source['description'] = self::LEGACY_DESCRIPTION;
            }
        } else {
            return;
        }

        $definitions['source_agent_id'] = $source;
        if ($usesJsonSchema) {
            $parameters['properties'] = $definitions;
        } else {
            $parameters = $definitions;
        }

        DB::table('agent_tool_registry')->where('id', $tool->id)->update([
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
