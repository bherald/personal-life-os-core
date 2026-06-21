<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflows') || ! Schema::hasTable('workflow_nodes') || ! Schema::hasTable('workflow_node_configs')) {
            return;
        }

        $nodeIds = $this->weatherNodeIds();
        foreach ($nodeIds as $nodeId) {
            $this->upsertConfig($nodeId, 'provider', 'nws');
            $this->upsertConfig($nodeId, 'fallback_provider', 'openweather');
            $this->upsertConfig($nodeId, 'forecast_days', '5');
        }

        DB::table('workflows')
            ->where('name', 'morning_weather')
            ->update([
                'description' => 'Daily Bloomsburg morning weather summary with NWS primary forecast and OpenWeather fallback',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflows') || ! Schema::hasTable('workflow_nodes') || ! Schema::hasTable('workflow_node_configs')) {
            return;
        }

        DB::table('workflow_node_configs')
            ->whereIn('workflow_node_id', $this->weatherNodeIds())
            ->whereIn('config_key', ['provider', 'fallback_provider', 'forecast_days'])
            ->delete();

        DB::table('workflows')
            ->where('name', 'morning_weather')
            ->update([
                'description' => 'Daily Bloomsburg morning weather summary with deterministic preformatted output',
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function weatherNodeIds(): array
    {
        return DB::table('workflow_nodes')
            ->join('workflows', 'workflows.id', '=', 'workflow_nodes.workflow_id')
            ->where('workflows.name', 'morning_weather')
            ->where('workflow_nodes.node_type', 'WeatherAPI')
            ->pluck('workflow_nodes.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function upsertConfig(int $nodeId, string $key, string $value): void
    {
        $existing = DB::table('workflow_node_configs')
            ->where('workflow_node_id', $nodeId)
            ->where('config_key', $key)
            ->first();

        if ($existing) {
            DB::table('workflow_node_configs')
                ->where('id', $existing->id)
                ->update(['config_value' => $value]);

            return;
        }

        DB::table('workflow_node_configs')->insert([
            'workflow_node_id' => $nodeId,
            'config_key' => $key,
            'config_value' => $value,
        ]);
    }
};
