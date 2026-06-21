<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BLOOMSBURG_LOCATION = '41.0037,-76.4549';

    private const BLOOMSBURG_FORECAST_URL = 'https://weather.com/weather/tenday/l/41.0037,-76.4549';

    private const LEGACY_LOCATION = '40.6259,-75.3703';

    private const LEGACY_FORECAST_URL = 'https://weather.com/weather/today/l/41.00,-76.46';

    public function up(): void
    {
        $this->updateWeatherApiLocation(self::BLOOMSBURG_LOCATION);
        $this->updatePushoverForecastUrl(self::BLOOMSBURG_FORECAST_URL);
    }

    public function down(): void
    {
        $this->updateWeatherApiLocation(self::LEGACY_LOCATION);
        $this->updatePushoverForecastUrl(self::LEGACY_FORECAST_URL);
    }

    private function updateWeatherApiLocation(string $location): void
    {
        foreach ($this->nodeIds('WeatherAPI') as $nodeId) {
            DB::table('workflow_node_configs')->updateOrInsert(
                [
                    'workflow_node_id' => $nodeId,
                    'config_key' => 'location',
                ],
                [
                    'config_value' => $location,
                ]
            );
        }
    }

    private function updatePushoverForecastUrl(string $url): void
    {
        foreach ($this->nodeIds('PushoverNotify') as $nodeId) {
            DB::table('workflow_node_configs')->updateOrInsert(
                [
                    'workflow_node_id' => $nodeId,
                    'config_key' => 'url',
                ],
                [
                    'config_value' => $url,
                ]
            );
        }
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
