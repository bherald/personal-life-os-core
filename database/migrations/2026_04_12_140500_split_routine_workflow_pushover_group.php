<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROUTINE_NODE_IDS = [44, 85, 155, 194, 270, 272];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::ROUTINE_NODE_IDS as $nodeId) {
                DB::table('workflow_node_configs')->updateOrInsert(
                    [
                        'workflow_node_id' => $nodeId,
                        'config_key' => 'source_group',
                    ],
                    [
                        'config_value' => 'workflow_routine_updates',
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('workflow_node_configs')
                ->whereIn('workflow_node_id', self::ROUTINE_NODE_IDS)
                ->where('config_key', 'source_group')
                ->where('config_value', 'workflow_routine_updates')
                ->delete();
        });
    }
};
