<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pushover accepted burst multipart sends with a zero inter-part delay, but
     * device delivery/display was unreliable for the earlier parts. Keep the
     * BatchProcessor time reserve from the prior migration and restore a small
     * delivery pace for human-readable multipart news notifications.
     */
    private const WORKFLOWS = [
        'news_brief',
        'Press_Enterprise_Headlines_Today',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'inter_chunk_delay_seconds', '1');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'inter_chunk_delay_seconds', '0');
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
            ->where('workflow_nodes.node_type', 'PushoverNotify')
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
};
