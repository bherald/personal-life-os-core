<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix post_agent_message tool definition: body parameter was marked required:true
 * but the PHP method has $body = '' (optional). LLMs occasionally omit body when
 * sending short status alerts, causing "Missing required parameter: body" errors.
 * Align DB definition with method signature: body is optional, defaults to ''.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tool = DB::selectOne("SELECT id, parameters FROM agent_tool_registry WHERE name = 'post_agent_message'");
        if (!$tool) {
            return;
        }

        $params = json_decode($tool->parameters, true);
        if (isset($params['body'])) {
            $params['body']['required'] = false;
            $params['body']['default'] = '';
            DB::update(
                "UPDATE agent_tool_registry SET parameters = ? WHERE name = 'post_agent_message'",
                [json_encode($params)]
            );
        }
    }

    public function down(): void
    {
        $tool = DB::selectOne("SELECT id, parameters FROM agent_tool_registry WHERE name = 'post_agent_message'");
        if (!$tool) {
            return;
        }

        $params = json_decode($tool->parameters, true);
        if (isset($params['body'])) {
            $params['body']['required'] = true;
            unset($params['body']['default']);
            DB::update(
                "UPDATE agent_tool_registry SET parameters = ? WHERE name = 'post_agent_message'",
                [json_encode($params)]
            );
        }
    }
};
