<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The app can confirm every multipart Pushover API call while the mobile
     * client still drops or collapses earlier reverse-sent news parts. Keep this
     * scoped to the two human news digests and give device delivery more room.
     */
    private const WORKFLOWS = [
        'news_brief',
        'Press_Enterprise_Headlines_Today',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'inter_chunk_delay_seconds', '3');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'inter_chunk_delay_seconds', '1');
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
};
