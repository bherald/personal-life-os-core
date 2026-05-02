<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $nodeIds = DB::table('workflow_nodes')
            ->whereIn('workflow_id', [7, 11])
            ->where('node_type', 'BatchProcessor')
            ->pluck('id');

        foreach ($nodeIds as $nodeId) {
            $exists = DB::table('workflow_node_configs')
                ->where('workflow_node_id', $nodeId)
                ->where('config_key', 'disable_recursion')
                ->exists();

            if ($exists) {
                DB::table('workflow_node_configs')
                    ->where('workflow_node_id', $nodeId)
                    ->where('config_key', 'disable_recursion')
                    ->update([
                        'config_value' => 'true',
                    ]);
            } else {
                DB::table('workflow_node_configs')->insert([
                    'workflow_node_id' => $nodeId,
                    'config_key' => 'disable_recursion',
                    'config_value' => 'true',
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('workflow_node_configs')
            ->whereIn('workflow_node_id', function ($query) {
                $query->select('id')
                    ->from('workflow_nodes')
                    ->whereIn('workflow_id', [7, 11])
                    ->where('node_type', 'BatchProcessor');
            })
            ->where('config_key', 'disable_recursion')
            ->delete();
    }
};
