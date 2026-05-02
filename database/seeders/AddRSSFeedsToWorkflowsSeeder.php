<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AddRSSFeedsToWorkflowsSeeder extends Seeder
{
    /**
     * Add RSS feed nodes to existing workflows
     * Blends RSS feeds with API sources for enhanced coverage
     */
    public function run(): void
    {
        $this->addRSSToNewsBrief();
        $this->addRSSToCybersecurityBrief();

        $this->command->info('✅ RSS feeds added to all news workflows!');
    }

    private function addRSSToNewsBrief(): void
    {
        $workflow = DB::table('workflows')->where('name', 'news_brief')->first();

        if (!$workflow) {
            $this->command->warn('⚠️  news_brief workflow not found');
            return;
        }

        // Add Spotlight PA RSS feed (node_order 4)
        $spotlightNodeId = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflow->id,
            'node_type' => 'RSSFeedReader',
            'node_order' => 4
        ]);

        // Configure Spotlight PA feed
        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $spotlightNodeId,
                'config_key' => 'feed_url',
                'config_value' => 'https://www.spotlightpa.org/feeds/full.xml'
            ],
            [
                'workflow_node_id' => $spotlightNodeId,
                'config_key' => 'limit',
                'config_value' => '10'
            ],
            [
                'workflow_node_id' => $spotlightNodeId,
                'config_key' => 'timeout',
                'config_value' => '10'
            ]
        ]);

        // Add ABC27 RSS feed (node_order 5)
        $abc27NodeId = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflow->id,
            'node_type' => 'RSSFeedReader',
            'node_order' => 5
        ]);

        // Configure ABC27 feed
        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $abc27NodeId,
                'config_key' => 'feed_url',
                'config_value' => 'https://www.abc27.com/feed/'
            ],
            [
                'workflow_node_id' => $abc27NodeId,
                'config_key' => 'limit',
                'config_value' => '10'
            ],
            [
                'workflow_node_id' => $abc27NodeId,
                'config_key' => 'timeout',
                'config_value' => '10'
            ]
        ]);

        // Add NPR RSS feed (node_order 6)
        $nprNodeId = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflow->id,
            'node_type' => 'RSSFeedReader',
            'node_order' => 6
        ]);

        // Configure NPR feed
        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $nprNodeId,
                'config_key' => 'feed_url',
                'config_value' => 'https://feeds.npr.org/1001/rss.xml'
            ],
            [
                'workflow_node_id' => $nprNodeId,
                'config_key' => 'limit',
                'config_value' => '10'
            ],
            [
                'workflow_node_id' => $nprNodeId,
                'config_key' => 'timeout',
                'config_value' => '10'
            ]
        ]);

        $this->command->info('✅ Added 3 RSS feeds to news_brief: Spotlight PA, ABC27, NPR');
    }

    private function addRSSToCybersecurityBrief(): void
    {
        $workflow = DB::table('workflows')->where('name', 'Cybersecurity News Brief')->first();

        if (!$workflow) {
            $this->command->warn('⚠️  Cybersecurity News Brief workflow not found');
            return;
        }

        // Add The Hacker News RSS feed (node_order 4)
        $hackerNewsNodeId = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflow->id,
            'node_type' => 'RSSFeedReader',
            'node_order' => 4
        ]);

        // Configure The Hacker News feed
        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $hackerNewsNodeId,
                'config_key' => 'feed_url',
                'config_value' => 'https://feeds.feedburner.com/TheHackersNews'
            ],
            [
                'workflow_node_id' => $hackerNewsNodeId,
                'config_key' => 'limit',
                'config_value' => '15'
            ],
            [
                'workflow_node_id' => $hackerNewsNodeId,
                'config_key' => 'timeout',
                'config_value' => '10'
            ]
        ]);

        // Add BleepingComputer RSS feed (node_order 5)
        $bleepingNodeId = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflow->id,
            'node_type' => 'RSSFeedReader',
            'node_order' => 5
        ]);

        // Configure BleepingComputer feed
        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $bleepingNodeId,
                'config_key' => 'feed_url',
                'config_value' => 'https://www.bleepingcomputer.com/feed/'
            ],
            [
                'workflow_node_id' => $bleepingNodeId,
                'config_key' => 'limit',
                'config_value' => '15'
            ],
            [
                'workflow_node_id' => $bleepingNodeId,
                'config_key' => 'timeout',
                'config_value' => '10'
            ]
        ]);

        // Add Krebs on Security RSS feed (node_order 6)
        $krebsNodeId = DB::table('workflow_nodes')->insertGetId([
            'workflow_id' => $workflow->id,
            'node_type' => 'RSSFeedReader',
            'node_order' => 6
        ]);

        // Configure Krebs feed
        DB::table('workflow_node_configs')->insert([
            [
                'workflow_node_id' => $krebsNodeId,
                'config_key' => 'feed_url',
                'config_value' => 'https://krebsonsecurity.com/feed/'
            ],
            [
                'workflow_node_id' => $krebsNodeId,
                'config_key' => 'limit',
                'config_value' => '10'
            ],
            [
                'workflow_node_id' => $krebsNodeId,
                'config_key' => 'timeout',
                'config_value' => '10'
            ]
        ]);

        $this->command->info('✅ Added 3 RSS feeds to Cybersecurity News Brief: Hacker News, BleepingComputer, Krebs');
    }
}
