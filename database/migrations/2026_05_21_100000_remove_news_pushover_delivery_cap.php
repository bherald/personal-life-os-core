<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The earlier delivery cap protected against uncertain client display, but
     * it now hides most of the daily news digest. Keep multipart pacing/proof
     * metadata and remove only the explicit cap from human news digests.
     */
    private const WORKFLOWS = [
        'news_brief',
        'Press_Enterprise_Headlines_Today',
    ];

    private const RESTORE_MAX_DELIVERY_PARTS = '3';

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                DB::table('workflow_node_configs')
                    ->where('workflow_node_id', $nodeId)
                    ->where('config_key', 'max_delivery_parts')
                    ->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                DB::table('workflow_node_configs')->updateOrInsert(
                    [
                        'workflow_node_id' => $nodeId,
                        'config_key' => 'max_delivery_parts',
                    ],
                    [
                        'config_value' => self::RESTORE_MAX_DELIVERY_PARTS,
                    ]
                );
            }
        });
    }

    /**
     * @return list<int>
     */
    private function pushoverNodeIds(): array
    {
        return DB::table('workflow_nodes')
            ->join('workflows', 'workflows.id', '=', 'workflow_nodes.workflow_id')
            ->whereIn('workflows.name', self::WORKFLOWS)
            ->where(function ($query): void {
                $query->where('workflow_nodes.node_type', 'PushoverNotify')
                    ->orWhere('workflow_nodes.node_type', 'like', '%\\PushoverNotify');
            })
            ->pluck('workflow_nodes.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
