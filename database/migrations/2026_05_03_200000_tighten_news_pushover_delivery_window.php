<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * These two workflows can produce multi-part routine Pushover messages. The
     * formatter should yield time to delivery instead of letting the notifier
     * start with only enough wall-clock left to send the final reversed part.
     */
    private const WORKFLOWS = [
        'news_brief',
        'Press_Enterprise_Headlines_Today',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->nodeIdsByType('BatchProcessor') as $nodeId) {
                $this->upsertConfig($nodeId, 'notification_reserve_seconds', '180');
                $this->upsertConfig($nodeId, 'min_ai_batch_timeout', '90');
            }

            foreach ($this->nodeIdsByType('PushoverNotify') as $nodeId) {
                $this->upsertConfig($nodeId, 'inter_chunk_delay_seconds', '0');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->nodeIdsByType('BatchProcessor') as $nodeId) {
                $this->deleteConfigIfValue($nodeId, 'notification_reserve_seconds', '180');
                $this->deleteConfigIfValue($nodeId, 'min_ai_batch_timeout', '90');
            }

            foreach ($this->nodeIdsByType('PushoverNotify') as $nodeId) {
                $this->deleteConfigIfValue($nodeId, 'inter_chunk_delay_seconds', '0');
            }
        });
    }

    /**
     * @return list<int>
     */
    private function nodeIdsByType(string $nodeType): array
    {
        return DB::table('workflow_nodes')
            ->join('workflows', 'workflows.id', '=', 'workflow_nodes.workflow_id')
            ->whereIn('workflows.name', self::WORKFLOWS)
            ->where('workflow_nodes.node_type', $nodeType)
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
