<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateCybersecurityDateFilterSeeder extends Seeder
{
    /**
     * Add max_age_hours and limit configuration to Cybersecurity News Brief workflow
     * - 48 hours old (today and yesterday)
     * - Maximum 30 articles total (3 per feed × 10 feeds, 0 for 2 feeds)
     */
    public function run(): void
    {
        $workflow = DB::table('workflows')->where('name', 'Cybersecurity News Brief')->first();

        if (!$workflow) {
            $this->command->warn('⚠️  Cybersecurity News Brief workflow not found');
            return;
        }

        // Get all RSSFeedReader nodes for this workflow
        $nodes = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->where('node_type', 'RSSFeedReader')
            ->orderBy('node_order')
            ->get();

        $updated = 0;
        $feedCount = 0;
        foreach ($nodes as $node) {
            $feedCount++;

            // First 10 feeds get 3 articles each (30 total)
            // Last 2 feeds get 0 to keep total at ~30
            $limit = $feedCount <= 10 ? '3' : '0';

            // Update or insert max_age_hours
            $existingAge = DB::table('workflow_node_configs')
                ->where('workflow_node_id', $node->id)
                ->where('config_key', 'max_age_hours')
                ->first();

            if ($existingAge) {
                DB::table('workflow_node_configs')
                    ->where('id', $existingAge->id)
                    ->update(['config_value' => '48']);
            } else {
                DB::table('workflow_node_configs')->insert([
                    'workflow_node_id' => $node->id,
                    'config_key' => 'max_age_hours',
                    'config_value' => '48'
                ]);
            }

            // Update or insert limit
            $existingLimit = DB::table('workflow_node_configs')
                ->where('workflow_node_id', $node->id)
                ->where('config_key', 'limit')
                ->first();

            if ($existingLimit) {
                DB::table('workflow_node_configs')
                    ->where('id', $existingLimit->id)
                    ->update(['config_value' => $limit]);
            } else {
                DB::table('workflow_node_configs')->insert([
                    'workflow_node_id' => $node->id,
                    'config_key' => 'limit',
                    'config_value' => $limit
                ]);
            }

            $updated++;
        }

        $this->command->info("✅ Updated {$updated} RSSFeedReader nodes in Cybersecurity News Brief workflow");
        $this->command->info("   Articles limited to 48 hours old (today and yesterday)");
        $this->command->info("   Maximum ~30 articles total (3 per feed for first 10 feeds)");
    }
}
