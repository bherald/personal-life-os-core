<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make each multipart news notification self-identifying in the message body
     * as well as the title. This is scoped to the two human news digests while
     * device visibility is being checked against API delivery proof.
     */
    private const WORKFLOWS = [
        'news_brief',
        'Press_Enterprise_Headlines_Today',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'part_headers_enabled', '1');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->pushoverNodeIds() as $nodeId) {
                $this->upsertConfig($nodeId, 'part_headers_enabled', '0');
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
