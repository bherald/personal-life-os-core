<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Long multipart news digests can be accepted by the Pushover API while the
     * mobile/desktop client only surfaces the newest packet. Keep the existing
     * ordering proof, but cap future human news digests to a short bounded
     * packet set with an explicit truncation marker.
     */
    private const WORKFLOWS = [
        'news_brief',
        'Press_Enterprise_Headlines_Today',
    ];

    private const MAX_DELIVERY_PARTS = '3';

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'max_delivery_parts', self::MAX_DELIVERY_PARTS);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->deleteConfigIfValue($nodeId, 'max_delivery_parts', self::MAX_DELIVERY_PARTS);
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

    private function upsertConfig(int $nodeId, string $key, string $value): void
    {
        DB::table('workflow_node_configs')->updateOrInsert(
            [
                'workflow_node_id' => $nodeId,
                'config_key' => $key,
            ],
            [
                'config_value' => $value,
            ]
        );
    }

    private function deleteConfigIfValue(int $nodeId, string $key, string $value): void
    {
        DB::table('workflow_node_configs')
            ->where('workflow_node_id', $nodeId)
            ->where('config_key', $key)
            ->where('config_value', $value)
            ->delete();
    }
};
