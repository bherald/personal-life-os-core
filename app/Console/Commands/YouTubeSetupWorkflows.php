<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Setup Workflows Command
 *
 * Creates the three YouTube automation workflows:
 * 1. Daily YouTube Research Digest (Tiers 1 & 2)
 * 2. Watch Later Auto-Processor (Tier 3)
 * 3. Manual URL Processor (On-Demand)
 */
class YouTubeSetupWorkflows extends Command
{
    protected $signature = 'youtube:setup-workflows
                            {--update : Update existing workflows if they exist}
                            {--tier1-channels= : Comma-separated Tier 1 channel IDs}
                            {--tier2-keywords= : Comma-separated Tier 2 keywords}';

    protected $description = 'Set up YouTube automation workflows (Phase 4)';

    public function handle(): int
    {
        $this->info("🎥 YouTube Workflow Setup - Phase 4");
        $this->info("═══════════════════════════════════");
        $this->newLine();

        $update = $this->option('update');
        $tier1Channels = $this->option('tier1-channels')
            ? explode(',', $this->option('tier1-channels'))
            : [];
        $tier2Keywords = $this->option('tier2-keywords')
            ? explode(',', $this->option('tier2-keywords'))
            : ['AI', 'automation', 'programming'];

        // Workflow 1: Daily YouTube Research Digest
        $this->info("Creating Workflow 1: Daily YouTube Research Digest");
        $workflow1 = $this->createDailyResearchDigest($tier1Channels, $tier2Keywords, $update);
        if ($workflow1) {
            $this->info("✅ Daily Research Digest created (ID: {$workflow1})");
        }

        // Workflow 2: Watch Later Auto-Processor
        $this->info("Creating Workflow 2: Watch Later Auto-Processor");
        $workflow2 = $this->createWatchLaterProcessor($update);
        if ($workflow2) {
            $this->info("✅ Watch Later Processor created (ID: {$workflow2})");
        }

        // Workflow 3: Manual URL Processor
        $this->info("Creating Workflow 3: Manual URL Processor");
        $workflow3 = $this->createManualUrlProcessor($update);
        if ($workflow3) {
            $this->info("✅ Manual URL Processor created (ID: {$workflow3})");
        }

        $this->newLine();
        $this->info("═══════════════════════════════════");
        $this->info("✅ YouTube Workflows Setup Complete!");
        $this->newLine();

        $this->info("Next Steps:");
        $this->line("1. Configure Tier 1 channels: php artisan youtube:setup-workflows --tier1-channels=UCxxx,UCyyy --update");
        $this->line("2. Activate workflows: Update 'active' column to 1 in workflows table");
        $this->line("3. Set up Laravel scheduler in crontab for automated execution");
        $this->line("4. Test workflows manually before enabling automation");

        return self::SUCCESS;
    }

    /**
     * Create Daily YouTube Research Digest workflow
     */
    private function createDailyResearchDigest(array $tier1Channels, array $tier2Keywords, bool $update): ?int
    {
        $workflowName = 'youtube_daily_digest';

        // Check if exists
        $existingWorkflow = DB::selectOne("SELECT id FROM workflows WHERE name = ?", [$workflowName]);

        if ($existingWorkflow) {
            if (!$update) {
                $this->warn("Workflow '{$workflowName}' already exists. Use --update to overwrite.");
                return null;
            }

            // Update existing workflow metadata
            DB::update("UPDATE workflows SET description = ?, schedule = ?, error_handling = ?, updated_at = ? WHERE id = ?", [
                'Automated daily digest of Tier 1 & 2 YouTube subscription videos',
                '0 7 * * *',
                'continue',
                now(),
                $existingWorkflow->id
            ]);

            $workflowId = $existingWorkflow->id;

            // Delete existing nodes and configs to recreate
            $existingNodeIds = DB::select("SELECT id FROM workflow_nodes WHERE workflow_id = ?", [$workflowId]);
            if (!empty($existingNodeIds)) {
                $nodeIds = array_column($existingNodeIds, 'id');
                $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
                DB::delete("DELETE FROM workflow_node_configs WHERE workflow_node_id IN ({$placeholders})", $nodeIds);
                DB::delete("DELETE FROM workflow_nodes WHERE workflow_id = ?", [$workflowId]);
            }
        } else {
            // Create new workflow
            DB::insert("INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                $workflowName,
                'Automated daily digest of Tier 1 & 2 YouTube subscription videos',
                '0 7 * * *',
                false,
                'continue',
                now(),
                now()
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();
        }

        // Node 1: YouTubeSubscriptions
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeSubscriptions', 1, now()
        ]);
        $node1Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node1Id, [
            'filter_channels' => json_encode($tier1Channels),
            'tier2_channels' => json_encode([]),
            'tier2_keywords' => json_encode($tier2Keywords),
            'max_age_hours' => '24',
            'min_duration' => '10',
            'max_duration' => '60',
            'limit' => '10',
        ]);

        // Node 2: PreviewNotification
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\PreviewNotification', 2, now()
        ]);
        $node2Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node2Id, [
            'notify_via' => 'pushover',
            'preview_window' => '60',
            'message' => 'Found {count} videos to process. Cancel within {window} minutes.',
        ]);

        // Node 3: WaitForCancellation
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\WaitForCancellation', 3, now()
        ]);
        $node3Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node3Id, [
            'wait_minutes' => '60',
            'default_action' => 'proceed',
        ]);

        // Node 4: YouTubeTranscript
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeTranscript', 4, now()
        ]);
        $node4Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node4Id, [
            'language' => 'en',
            'batch_size' => '5',
        ]);

        // Node 5: YouTubeJoplinCreate
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeJoplinCreate', 5, now()
        ]);
        $node5Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node5Id, [
            'notebook' => 'YouTube Research',
            'create_notes' => 'true',
        ]);

        // Node 6: PushoverNotify
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\PushoverNotify', 6, now()
        ]);
        $node6Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node6Id, [
            'title' => '📺 YouTube Research Digest',
            'message' => 'Processed {count} videos - Notes in Joplin',
            'priority' => '0',
        ]);

        return $workflowId;
    }

    /**
     * Create Watch Later Auto-Processor workflow
     */
    private function createWatchLaterProcessor(bool $update): ?int
    {
        $workflowName = 'youtube_watch_later';

        $existingWorkflow = DB::selectOne("SELECT id FROM workflows WHERE name = ?", [$workflowName]);

        if ($existingWorkflow) {
            if (!$update) {
                $this->warn("Workflow '{$workflowName}' already exists. Use --update to overwrite.");
                return null;
            }

            DB::update("UPDATE workflows SET description = ?, schedule = ?, error_handling = ?, updated_at = ? WHERE id = ?", [
                'Automated processing of Watch Later playlist videos (Tier 3)',
                '0 22 * * *',
                'continue',
                now(),
                $existingWorkflow->id
            ]);

            $workflowId = $existingWorkflow->id;

            $existingNodeIds = DB::select("SELECT id FROM workflow_nodes WHERE workflow_id = ?", [$workflowId]);
            if (!empty($existingNodeIds)) {
                $nodeIds = array_column($existingNodeIds, 'id');
                $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
                DB::delete("DELETE FROM workflow_node_configs WHERE workflow_node_id IN ({$placeholders})", $nodeIds);
                DB::delete("DELETE FROM workflow_nodes WHERE workflow_id = ?", [$workflowId]);
            }
        } else {
            DB::insert("INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                $workflowName,
                'Automated processing of Watch Later playlist videos (Tier 3)',
                '0 22 * * *',
                false,
                'continue',
                now(),
                now()
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();
        }

        // Node 1: YouTubePlaylist
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubePlaylist', 1, now()
        ]);
        $node1Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node1Id, [
            'playlist_id' => 'WL',
            'since_last_run' => 'true',
            'limit' => '50',
        ]);

        // Node 2: YouTubeTranscript
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeTranscript', 2, now()
        ]);
        $node2Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node2Id, [
            'language' => 'en',
            'batch_size' => '5',
        ]);

        // Node 3: YouTubeJoplinCreate
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeJoplinCreate', 3, now()
        ]);
        $node3Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node3Id, [
            'notebook' => 'YouTube - Watch Later',
            'create_notes' => 'true',
        ]);

        // Node 4: PushoverNotify
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\PushoverNotify', 4, now()
        ]);
        $node4Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node4Id, [
            'title' => '✅ Watch Later Processed',
            'message' => 'Processed {count} videos - Notes in Joplin',
            'priority' => '0',
            'only_if' => 'count > 0',
        ]);

        return $workflowId;
    }

    /**
     * Create Manual URL Processor workflow
     */
    private function createManualUrlProcessor(bool $update): ?int
    {
        $workflowName = 'youtube_manual_process';

        $existingWorkflow = DB::selectOne("SELECT id FROM workflows WHERE name = ?", [$workflowName]);

        if ($existingWorkflow) {
            if (!$update) {
                $this->warn("Workflow '{$workflowName}' already exists. Use --update to overwrite.");
                return null;
            }

            DB::update("UPDATE workflows SET description = ?, schedule = ?, error_handling = ?, updated_at = ? WHERE id = ?", [
                'On-demand processing of individual YouTube URLs',
                null,
                'continue',
                now(),
                $existingWorkflow->id
            ]);

            $workflowId = $existingWorkflow->id;

            $existingNodeIds = DB::select("SELECT id FROM workflow_nodes WHERE workflow_id = ?", [$workflowId]);
            if (!empty($existingNodeIds)) {
                $nodeIds = array_column($existingNodeIds, 'id');
                $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
                DB::delete("DELETE FROM workflow_node_configs WHERE workflow_node_id IN ({$placeholders})", $nodeIds);
                DB::delete("DELETE FROM workflow_nodes WHERE workflow_id = ?", [$workflowId]);
            }
        } else {
            DB::insert("INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                $workflowName,
                'On-demand processing of individual YouTube URLs',
                null,
                true,
                'continue',
                now(),
                now()
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();
        }

        // Node 1: YouTubeManualInput
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeManualInput', 1, now()
        ]);
        $node1Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node1Id, [
            'accept_url' => 'true',
            'extract_video_id' => 'true',
            'validate' => 'true',
        ]);

        // Node 2: YouTubeTranscript
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeTranscript', 2, now()
        ]);
        $node2Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node2Id, [
            'language' => 'en',
        ]);

        // Node 3: YouTubeJoplinCreate
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeJoplinCreate', 3, now()
        ]);
        $node3Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node3Id, [
            'notebook' => 'YouTube - Manual',
            'create_notes' => 'true',
        ]);

        // Node 4: PushoverNotify
        DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
            $workflowId, 'App\\Nodes\\PushoverNotify', 4, now()
        ]);
        $node4Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node4Id, [
            'title' => '📺 Video Processed',
            'message' => '{video_title} saved to Joplin',
            'priority' => '0',
        ]);

        return $workflowId;
    }

    /**
     * Helper to insert node configs using raw SQL
     */
    private function insertNodeConfigs(int $nodeId, array $configs): void
    {
        foreach ($configs as $key => $value) {
            DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
                $nodeId, $key, $value
            ]);
        }
    }
}
