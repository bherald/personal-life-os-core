<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROUTINE_NODE_ID = 248;

    public function up(): void
    {
        if (! DB::table('workflow_nodes')->where('id', self::ROUTINE_NODE_ID)->exists()) {
            return;
        }

        DB::table('workflow_node_configs')->updateOrInsert(
            [
                'workflow_node_id' => self::ROUTINE_NODE_ID,
                'config_key' => 'source_group',
            ],
            [
                'config_value' => 'workflow_routine_updates',
            ]
        );
    }

    public function down(): void
    {
        DB::table('workflow_node_configs')
            ->where('workflow_node_id', self::ROUTINE_NODE_ID)
            ->where('config_key', 'source_group')
            ->where('config_value', 'workflow_routine_updates')
            ->delete();
    }
};
