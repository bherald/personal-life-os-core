<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TOOL_NAME = 'workflow_resume_execution';

    private const DESCRIPTION_SUFFIX = ' Requires explicit execution_intent=resume_live; no implicit or test-mode resume is available.';

    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $row = DB::selectOne(
            'SELECT id, parameters, description
             FROM agent_tool_registry
             WHERE name = ?
               AND service_class = ?
               AND method = ?
             LIMIT 1',
            [self::TOOL_NAME, 'App\\Services\\WorkflowOpsService', 'resumeExecution']
        );
        if ($row === null) {
            return;
        }

        $parameters = json_decode((string) $row->parameters, true);
        $parameters = is_array($parameters) ? $parameters : [];
        $parameters['execution_intent'] = [
            'type' => 'string',
            'required' => true,
            'enum' => ['resume_live'],
            'description' => 'Required explicit intent to resume the failed workflow as a live execution',
        ];

        $description = rtrim((string) $row->description);
        if (! str_contains($description, trim(self::DESCRIPTION_SUFFIX))) {
            $description .= self::DESCRIPTION_SUFFIX;
        }

        DB::update(
            'UPDATE agent_tool_registry
             SET parameters = ?, description = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [json_encode($parameters, JSON_UNESCAPED_SLASHES), $description, $row->id]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $row = DB::selectOne(
            'SELECT id, parameters, description
             FROM agent_tool_registry
             WHERE name = ?
               AND service_class = ?
               AND method = ?
             LIMIT 1',
            [self::TOOL_NAME, 'App\\Services\\WorkflowOpsService', 'resumeExecution']
        );
        if ($row === null) {
            return;
        }

        $parameters = json_decode((string) $row->parameters, true);
        $parameters = is_array($parameters) ? $parameters : [];
        unset($parameters['execution_intent']);
        $description = str_replace(self::DESCRIPTION_SUFFIX, '', (string) $row->description);

        DB::update(
            'UPDATE agent_tool_registry
             SET parameters = ?, description = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [json_encode($parameters, JSON_UNESCAPED_SLASHES), $description, $row->id]
        );
    }
};
