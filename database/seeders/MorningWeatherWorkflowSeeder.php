<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MorningWeatherWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        // Check if workflow already exists
        $existingWorkflow = DB::table('workflows')->where('name', 'morning_weather')->first();

        if ($existingWorkflow) {
            $this->command->info('Workflow "morning_weather" already exists. Updating...');

            // Update existing workflow
            DB::table('workflows')
                ->where('id', $existingWorkflow->id)
                ->update([
                    'description' => 'Daily Bloomsburg morning weather summary with deterministic preformatted output',
                    'schedule' => '0 7 * * *',
                    'active' => true,
                    'error_handling' => 'continue',
                    'updated_at' => now(),
                ]);

            $workflowId = $existingWorkflow->id;

            // Check if nodes already exist
            $existingNodes = DB::table('workflow_nodes')->where('workflow_id', $workflowId)->exists();

            if ($existingNodes) {
                $this->command->info('Workflow nodes already exist. Skipping node creation.');
                $this->command->info('Workflow updated successfully!');
                $this->command->info('Run with: php artisan workflow:run morning_weather');

                return;
            }
        } else {
            // Create workflow
            $workflowId = DB::table('workflows')->insertGetId([
                'name' => 'morning_weather',
                'description' => 'Daily Bloomsburg morning weather summary with deterministic preformatted output',
                'schedule' => '0 7 * * *', // 7am daily
                'active' => true,
                'error_handling' => 'continue',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Create or update retry config
        $existingRetryConfig = DB::table('retry_configs')->where('workflow_id', $workflowId)->first();

        if ($existingRetryConfig) {
            DB::table('retry_configs')
                ->where('id', $existingRetryConfig->id)
                ->update([
                    'max_attempts' => 3,
                    'notify_on_failure' => 'pushover',
                ]);
            $retryConfigId = $existingRetryConfig->id;

            // Delete existing backoff intervals and recreate
            DB::table('retry_backoff_intervals')->where('retry_config_id', $retryConfigId)->delete();
        } else {
            $retryConfigId = DB::table('retry_configs')->insertGetId([
                'workflow_id' => $workflowId,
                'max_attempts' => 3,
                'notify_on_failure' => 'pushover',
            ]);
        }

        // Create retry backoff intervals
        DB::table('retry_backoff_intervals')->insert([
            ['retry_config_id' => $retryConfigId, 'attempt_number' => 1, 'backoff_seconds' => 5],
            ['retry_config_id' => $retryConfigId, 'attempt_number' => 2, 'backoff_seconds' => 15],
            ['retry_config_id' => $retryConfigId, 'attempt_number' => 3, 'backoff_seconds' => 60],
        ]);

        // Node 1: WeatherAPI
        $node1Id = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflowId,
            'node_type' => 'WeatherAPI',
            'node_order' => 1,
            'created_at' => now(),
        ]);

        DB::table('workflow_node_configs')->insert([
            ['workflow_node_id' => $node1Id, 'config_key' => 'location', 'config_value' => '41.0037,-76.4549'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'units', 'config_value' => 'imperial'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'provider', 'config_value' => 'nws'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'fallback_provider', 'config_value' => 'openweather'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'forecast_days', 'config_value' => '5'],
        ]);

        // Node 2: AIFormatter passthrough for preformatted Pushover text
        $node2Id = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflowId,
            'node_type' => 'AIFormatter',
            'node_order' => 2,
            'created_at' => now(),
        ]);

        $aiPrompt = 'Pass through WeatherAPI preformatted_weather for Pushover.

This workflow is deterministic: if preformatted_weather is present, return it exactly as formatted. Do not rewrite, summarize, reorder, or add commentary.';

        DB::table('workflow_node_configs')->insert([
            ['workflow_node_id' => $node2Id, 'config_key' => 'prompt', 'config_value' => $aiPrompt],
            ['workflow_node_id' => $node2Id, 'config_key' => 'response_format', 'config_value' => 'text'],
            ['workflow_node_id' => $node2Id, 'config_key' => 'ai_timeout', 'config_value' => '60'],
            ['workflow_node_id' => $node2Id, 'config_key' => 'pushover_format', 'config_value' => 'monospace'],
            ['workflow_node_id' => $node2Id, 'config_key' => 'prefer_preformatted', 'config_value' => 'true'],
        ]);

        // Node 3: PushoverNotify - Monospace format
        $node3Id = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflowId,
            'node_type' => 'PushoverNotify',
            'node_order' => 3,
            'created_at' => now(),
        ]);

        DB::table('workflow_node_configs')->insert([
            ['workflow_node_id' => $node3Id, 'config_key' => 'title', 'config_value' => '☀️ Morning Weather'],
            ['workflow_node_id' => $node3Id, 'config_key' => 'priority', 'config_value' => '0'],
            ['workflow_node_id' => $node3Id, 'config_key' => 'format_type', 'config_value' => 'monospace'],
            ['workflow_node_id' => $node3Id, 'config_key' => 'url', 'config_value' => 'https://weather.com/weather/tenday/l/41.0037,-76.4549'],
            ['workflow_node_id' => $node3Id, 'config_key' => 'url_title', 'config_value' => 'Full Weather Forecast'],
        ]);

        echo "Morning weather workflow created successfully!\n";
        echo "Run with: php artisan workflow:run morning_weather\n";
    }
}
