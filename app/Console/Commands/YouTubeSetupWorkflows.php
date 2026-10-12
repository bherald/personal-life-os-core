<?php

namespace App\Console\Commands;

use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    private const TRANSCRIPT_POLICY_KEY = 'youtube.transcript_runtime_policy';

    protected $signature = 'youtube:setup-workflows
                            {--update : Preserve existing workflows and apply only explicit channel/keyword options}
                            {--tier1-channels= : Comma-separated Tier 1 channel IDs}
                            {--tier2-keywords= : Comma-separated Tier 2 keywords}';

    protected $description = 'Set up YouTube automation workflows (Phase 4)';

    public function handle(SystemConfigService $systemConfigService): int
    {
        $transcriptDefaults = $this->transcriptDefaults($systemConfigService);
        if ($transcriptDefaults === null) {
            $this->error('Invalid or missing system_configs.'.self::TRANSCRIPT_POLICY_KEY.' setup defaults.');

            return self::INVALID;
        }

        $this->info('🎥 YouTube Workflow Setup - Phase 4');
        $this->info('═══════════════════════════════════');
        $this->newLine();

        $update = $this->option('update');
        $tier1Provided = $this->option('tier1-channels') !== null;
        $tier2Provided = $this->option('tier2-keywords') !== null;
        $tier1Channels = $tier1Provided
            ? $this->csvValues((string) $this->option('tier1-channels'))
            : [];
        $tier2Keywords = $tier2Provided
            ? $this->csvValues((string) $this->option('tier2-keywords'))
            : ['AI', 'automation', 'programming'];

        // Workflow 1: Daily YouTube Research Digest
        $this->info('Creating Workflow 1: Daily YouTube Research Digest');
        $workflow1 = $this->createDailyResearchDigest(
            $tier1Channels,
            $tier2Keywords,
            $tier1Provided,
            $tier2Provided,
            $update,
            $transcriptDefaults
        );
        if ($workflow1) {
            $this->info("✅ Daily Research Digest ready (ID: {$workflow1})");
        }

        // Workflow 2: Watch Later Auto-Processor
        $this->info('Creating Workflow 2: Watch Later Auto-Processor');
        $workflow2 = $this->createWatchLaterProcessor($update, $transcriptDefaults);
        if ($workflow2) {
            $this->info("✅ Watch Later Processor ready (ID: {$workflow2})");
        }

        // Workflow 3: Manual URL Processor
        $this->info('Creating Workflow 3: Manual URL Processor');
        $workflow3 = $this->createManualUrlProcessor($update, $transcriptDefaults);
        if ($workflow3) {
            $this->info("✅ Manual URL Processor ready (ID: {$workflow3})");
        }

        $this->newLine();
        $this->info('═══════════════════════════════════');
        $this->info('✅ YouTube Workflows Setup Complete!');
        $this->newLine();

        $this->info('Next Steps:');
        $this->line('1. Update only channel/keyword selections: php artisan youtube:setup-workflows --tier1-channels=UCxxx,UCyyy --update');
        $this->line("2. Activate workflows: Update 'active' column to 1 in workflows table");
        $this->line('3. Set up Laravel scheduler in crontab for automated execution');
        $this->line('4. Test workflows manually before enabling automation');

        return self::SUCCESS;
    }

    /**
     * Create Daily YouTube Research Digest workflow
     */
    private function createDailyResearchDigest(
        array $tier1Channels,
        array $tier2Keywords,
        bool $tier1Provided,
        bool $tier2Provided,
        bool $update,
        array $transcriptDefaults
    ): ?int {
        $workflowName = 'youtube_daily_digest';

        // Check if exists
        $existingWorkflow = DB::selectOne('SELECT id FROM workflows WHERE name = ?', [$workflowName]);

        if ($existingWorkflow) {
            if (! $update) {
                $this->warn("Workflow '{$workflowName}' already exists. Use --update only for preservation-safe explicit option updates.");

                return null;
            }

            if ($tier1Provided) {
                $this->updateExistingNodeConfig(
                    (int) $existingWorkflow->id,
                    'App\\Nodes\\YouTube\\YouTubeSubscriptions',
                    'filter_channels',
                    json_encode($tier1Channels, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
            }
            if ($tier2Provided) {
                $this->updateExistingNodeConfig(
                    (int) $existingWorkflow->id,
                    'App\\Nodes\\YouTube\\YouTubeSubscriptions',
                    'tier2_keywords',
                    json_encode($tier2Keywords, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
            }
            $this->line("Preserved existing workflow topology and operator-owned configuration for '{$workflowName}'.");

            return (int) $existingWorkflow->id;
        } else {
            // Create new workflow
            DB::insert('INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $workflowName,
                'Automated daily digest of Tier 1 & 2 YouTube subscription videos',
                '0 7 * * *',
                false,
                'continue',
                now(),
                now(),
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();
        }

        // Node 1: YouTubeSubscriptions
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeSubscriptions', 1, now(),
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
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\PreviewNotification', 2, now(),
        ]);
        $node2Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node2Id, [
            'notify_via' => 'pushover',
            'preview_window' => '60',
            'message' => 'Found {count} videos to process. Cancel within {window} minutes.',
        ]);

        // Node 3: WaitForCancellation
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\WaitForCancellation', 3, now(),
        ]);
        $node3Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node3Id, [
            'wait_minutes' => '60',
            'default_action' => 'proceed',
        ]);

        // Node 4: YouTubeTranscript
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeTranscript', 4, now(),
        ]);
        $node4Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node4Id, [
            'language' => $transcriptDefaults['default_language'],
            'batch_size' => (string) $transcriptDefaults['default_batch_size'],
        ]);

        // Node 5: YouTubeJoplinCreate
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeJoplinCreate', 5, now(),
        ]);
        $node5Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node5Id, [
            'notebook' => 'YouTube Research',
            'create_notes' => 'true',
        ]);

        // Node 6: PushoverNotify
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\PushoverNotify', 6, now(),
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
    private function createWatchLaterProcessor(bool $update, array $transcriptDefaults): ?int
    {
        $workflowName = 'youtube_watch_later';

        $existingWorkflow = DB::selectOne('SELECT id FROM workflows WHERE name = ?', [$workflowName]);

        if ($existingWorkflow) {
            if (! $update) {
                $this->warn("Workflow '{$workflowName}' already exists. Use --update only for preservation-safe explicit option updates.");

                return null;
            }

            $this->line("Preserved existing workflow topology and operator-owned configuration for '{$workflowName}'.");

            return (int) $existingWorkflow->id;
        } else {
            DB::insert('INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $workflowName,
                'Automated processing of Watch Later playlist videos (Tier 3)',
                '0 22 * * *',
                false,
                'continue',
                now(),
                now(),
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();
        }

        // Node 1: YouTubePlaylist
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubePlaylist', 1, now(),
        ]);
        $node1Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node1Id, [
            'playlist_id' => 'WL',
            'since_last_run' => 'true',
            'limit' => '50',
        ]);

        // Node 2: YouTubeTranscript
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeTranscript', 2, now(),
        ]);
        $node2Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node2Id, [
            'language' => $transcriptDefaults['default_language'],
            'batch_size' => (string) $transcriptDefaults['default_batch_size'],
        ]);

        // Node 3: YouTubeJoplinCreate
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeJoplinCreate', 3, now(),
        ]);
        $node3Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node3Id, [
            'notebook' => 'YouTube - Watch Later',
            'create_notes' => 'true',
        ]);

        // Node 4: PushoverNotify
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\PushoverNotify', 4, now(),
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
    private function createManualUrlProcessor(bool $update, array $transcriptDefaults): ?int
    {
        $workflowName = 'youtube_manual_process';

        $existingWorkflow = DB::selectOne('SELECT id FROM workflows WHERE name = ?', [$workflowName]);

        if ($existingWorkflow) {
            if (! $update) {
                $this->warn("Workflow '{$workflowName}' already exists. Use --update only for preservation-safe explicit option updates.");

                return null;
            }

            $this->line("Preserved existing workflow topology and operator-owned configuration for '{$workflowName}'.");

            return (int) $existingWorkflow->id;
        } else {
            DB::insert('INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $workflowName,
                'On-demand processing of individual YouTube URLs',
                null,
                true,
                'continue',
                now(),
                now(),
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();
        }

        // Node 1: YouTubeManualInput
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeManualInput', 1, now(),
        ]);
        $node1Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node1Id, [
            'accept_url' => 'true',
            'extract_video_id' => 'true',
            'validate' => 'true',
        ]);

        // Node 2: YouTubeTranscript
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeTranscript', 2, now(),
        ]);
        $node2Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node2Id, [
            'language' => $transcriptDefaults['default_language'],
        ]);

        // Node 3: YouTubeJoplinCreate
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\YouTube\\YouTubeJoplinCreate', 3, now(),
        ]);
        $node3Id = (int) DB::getPdo()->lastInsertId();

        $this->insertNodeConfigs($node3Id, [
            'notebook' => 'YouTube - Manual',
            'create_notes' => 'true',
        ]);

        // Node 4: PushoverNotify
        DB::insert('INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)', [
            $workflowId, 'App\\Nodes\\PushoverNotify', 4, now(),
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
            DB::insert('INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)', [
                $nodeId, $key, $value,
            ]);
        }
    }

    private function updateExistingNodeConfig(int $workflowId, string $nodeType, string $key, string $value): void
    {
        $node = DB::selectOne(
            'SELECT id FROM workflow_nodes WHERE workflow_id = ? AND node_type = ? ORDER BY node_order, id LIMIT 1',
            [$workflowId, $nodeType]
        );
        if ($node === null) {
            $this->warn("Existing workflow has no {$nodeType} node; skipped {$key} update.");

            return;
        }

        $existing = DB::selectOne(
            'SELECT workflow_node_id FROM workflow_node_configs WHERE workflow_node_id = ? AND config_key = ? LIMIT 1',
            [$node->id, $key]
        );
        if ($existing === null) {
            DB::insert(
                'INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)',
                [$node->id, $key, $value]
            );

            return;
        }

        DB::update(
            'UPDATE workflow_node_configs SET config_value = ? WHERE workflow_node_id = ? AND config_key = ?',
            [$value, $node->id, $key]
        );
    }

    /** @return array{default_language:string,default_batch_size:int}|null */
    private function transcriptDefaults(SystemConfigService $systemConfigService): ?array
    {
        $policy = $systemConfigService->get(self::TRANSCRIPT_POLICY_KEY);
        if (! is_array($policy)) {
            return null;
        }

        $language = trim((string) ($policy['default_language'] ?? ''));
        $batchSize = filter_var($policy['default_batch_size'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($language === '' || $batchSize === false) {
            return null;
        }

        return [
            'default_language' => $language,
            'default_batch_size' => $batchSize,
        ];
    }

    /** @return list<string> */
    private function csvValues(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        )));
    }
}
