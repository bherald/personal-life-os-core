<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdatePARegionalFeedsSeeder extends Seeder
{
    /**
     * Replace ABC27 (Harrisburg) with WNEP (Northeastern & Central PA)
     * Better coverage for North/Northeast Pennsylvania
     */
    public function run(): void
    {
        $this->replaceABC27WithWNEP();

        $this->command->info('✅ Pennsylvania regional feeds updated!');
    }

    private function replaceABC27WithWNEP(): void
    {
        // Find news_brief workflow
        $workflow = DB::table('workflows')->where('name', 'news_brief')->first();

        if (!$workflow) {
            $this->command->warn('⚠️  news_brief workflow not found');
            return;
        }

        // Find ABC27 RSS node (node_order 5)
        $abc27Node = DB::table('workflow_nodes')
            ->where('workflow_id', $workflow->id)
            ->where('node_type', 'RSSFeedReader')
            ->where('node_order', 5)
            ->first();

        if (!$abc27Node) {
            $this->command->warn('⚠️  ABC27 RSS node not found');
            return;
        }

        // Update feed_url to WNEP
        $updated = DB::table('workflow_node_configs')
            ->where('workflow_node_id', $abc27Node->id)
            ->where('config_key', 'feed_url')
            ->update(['config_value' => 'https://www.wnep.com/feeds/syndication/rss/news']);

        if ($updated) {
            $this->command->info('✅ Replaced ABC27 (Harrisburg) with WNEP (Northeastern & Central PA)');
            $this->command->info('   Old: https://www.abc27.com/feed/');
            $this->command->info('   New: https://www.wnep.com/feeds/syndication/rss/news');
        } else {
            $this->command->warn('⚠️  Feed URL not updated (may already be current)');
        }
    }
}
