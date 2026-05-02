<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NewsBriefWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        // Check if workflow already exists
        $existingWorkflow = DB::table('workflows')->where('name', 'news_brief')->first();

        if ($existingWorkflow) {
            $this->command->info('Workflow "news_brief" already exists. Updating...');

            // Update existing workflow
            DB::table('workflows')
                ->where('id', $existingWorkflow->id)
                ->update([
                    'description' => 'Daily morning news brief: Top 10 US national, Pennsylvania, and Bloomsburg area news',
                    'schedule' => '15 6 * * *', // 6:15 AM daily
                    'active' => true,
                    'error_handling' => 'continue',
                    'updated_at' => now()
                ]);

            $workflowId = $existingWorkflow->id;

            // Check if nodes already exist
            $existingNodes = DB::table('workflow_nodes')->where('workflow_id', $workflowId)->exists();

            if ($existingNodes) {
                $this->command->info('Workflow nodes already exist. Skipping node creation.');
                $this->command->info('Workflow updated successfully!');
                $this->command->info('Run with: php artisan workflow:run news_brief');
                return;
            }
        } else {
            // Create workflow
            $workflowId = DB::table('workflows')->insertGetId([
                'name' => 'news_brief',
                'description' => 'Daily morning news brief: Top 10 US national, Pennsylvania, and Bloomsburg area news',
                'schedule' => '15 6 * * *', // 6:15 AM daily
                'active' => true,
                'error_handling' => 'continue',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Create or update retry config
        $existingRetryConfig = DB::table('retry_configs')->where('workflow_id', $workflowId)->first();

        if ($existingRetryConfig) {
            DB::table('retry_configs')
                ->where('id', $existingRetryConfig->id)
                ->update([
                    'max_attempts' => 3,
                    'notify_on_failure' => 'pushover'
                ]);
            $retryConfigId = $existingRetryConfig->id;

            // Delete existing backoff intervals and recreate
            DB::table('retry_backoff_intervals')->where('retry_config_id', $retryConfigId)->delete();
        } else {
            $retryConfigId = DB::table('retry_configs')->insertGetId([
                'workflow_id' => $workflowId,
                'max_attempts' => 3,
                'notify_on_failure' => 'pushover'
            ]);
        }

        // Create retry backoff intervals
        DB::table('retry_backoff_intervals')->insert([
            ['retry_config_id' => $retryConfigId, 'attempt_number' => 1, 'backoff_seconds' => 5],
            ['retry_config_id' => $retryConfigId, 'attempt_number' => 2, 'backoff_seconds' => 15],
            ['retry_config_id' => $retryConfigId, 'attempt_number' => 3, 'backoff_seconds' => 60],
        ]);

        // Node 1: WebSearch for US National News
        $node1Id = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflowId,
            'node_type' => 'WebSearch',
            'node_order' => 1,
            'created_at' => now()
        ]);

        DB::table('workflow_node_configs')->insert([
            ['workflow_node_id' => $node1Id, 'config_key' => 'query', 'config_value' => 'Latest news today: US national news, Pennsylvania state news, Bloomsburg PA and Columbia County Pennsylvania area news'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'count', 'config_value' => '15'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'search_engine', 'config_value' => 'brave'],
            ['workflow_node_id' => $node1Id, 'config_key' => 'fallback', 'config_value' => 'true'],
        ]);

        // Node 2: AIFormatter
        $node2Id = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflowId,
            'node_type' => 'AIFormatter',
            'node_order' => 2,
            'created_at' => now()
        ]);

        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $node2Id,
                'config_key' => 'prompt',
                'config_value' => 'Create a TOP 10 NEWS BRIEF from these search results. Focus on non-biased, factual news reporting. Organize as follows:

🇺🇸 US NATIONAL NEWS (3-4 items)
- Select the most important US national news stories

🏛️ PENNSYLVANIA NEWS (3-4 items)
- Focus on Pennsylvania state-level news

📍 BLOOMSBURG & COLUMBIA COUNTY AREA (2-3 items)
- Local news within 50 miles of Bloomsburg, PA (including Berwick, Danville, Hazleton, Wilkes-Barre area)

For each news item, provide:
- Clear headline
- 1-2 sentence summary
- Source URL

Keep the brief concise (max 400 words total). Prioritize recent, significant stories. Avoid opinion pieces or highly partisan content. Use bullet points for readability.'
            ],
            ['workflow_node_id' => $node2Id, 'config_key' => 'response_format', 'config_value' => 'text'],
            ['workflow_node_id' => $node2Id, 'config_key' => 'ai_timeout', 'config_value' => '60'],
        ]);

        // Node 3: PushoverNotify
        $node3Id = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflowId,
            'node_type' => 'PushoverNotify',
            'node_order' => 3,
            'created_at' => now()
        ]);

        DB::table('workflow_node_configs')->insert([
            ['workflow_node_id' => $node3Id, 'config_key' => 'title', 'config_value' => '📰 Morning News Brief'],
            ['workflow_node_id' => $node3Id, 'config_key' => 'priority', 'config_value' => '0'],
        ]);

        echo "News brief workflow created successfully!\n";
        echo "Schedule: 6:15 AM daily (15 6 * * *)\n";
        echo "Run manually with: php artisan workflow:run news_brief\n";
    }
}
