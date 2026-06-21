<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PASSTHROUGH_PROMPT = <<<'PROMPT'
Pass through WeatherAPI preformatted_weather for Pushover.

This workflow is deterministic: if preformatted_weather is present, return it exactly as formatted. Do not rewrite, summarize, reorder, or add commentary.
PROMPT;

    private const PREVIOUS_PROMPT_PREFIX = 'Format weather data as MONOSPACE text for Pushover.';

    public function up(): void
    {
        foreach ($this->nodeIds('AIFormatter') as $nodeId) {
            $this->upsertConfig($nodeId, 'prompt', self::PASSTHROUGH_PROMPT);
            $this->upsertConfig($nodeId, 'response_format', 'text');
            $this->upsertConfig($nodeId, 'pushover_format', 'monospace');
            $this->upsertConfig($nodeId, 'prefer_preformatted', 'true');
        }

        foreach ($this->nodeIds('PushoverNotify') as $nodeId) {
            $this->upsertConfig($nodeId, 'format_type', 'monospace');
            $this->upsertConfig($nodeId, 'url', 'https://weather.com/weather/tenday/l/41.0037,-76.4549');
            $this->upsertConfig($nodeId, 'url_title', 'Full Weather Forecast');
        }
    }

    public function down(): void
    {
        foreach ($this->nodeIds('AIFormatter') as $nodeId) {
            DB::table('workflow_node_configs')
                ->where('workflow_node_id', $nodeId)
                ->where('config_key', 'prefer_preformatted')
                ->delete();

            $this->upsertConfig($nodeId, 'prompt', self::PREVIOUS_PROMPT_PREFIX);
        }
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

    /**
     * @return array<int, int>
     */
    private function nodeIds(string $nodeType): array
    {
        return DB::table('workflow_nodes')
            ->join('workflows', 'workflows.id', '=', 'workflow_nodes.workflow_id')
            ->where('workflows.name', 'morning_weather')
            ->where(function ($query) use ($nodeType): void {
                $query->where('workflow_nodes.node_type', $nodeType)
                    ->orWhere('workflow_nodes.node_type', 'like', '%\\'.$nodeType);
            })
            ->pluck('workflow_nodes.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
};