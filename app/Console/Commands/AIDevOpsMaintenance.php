<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use App\Services\AIService;
use App\Services\ProcessHealthFlagService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * AI-Driven DevOps Maintenance Command (E22)
 *
 * Runs during 3-5 AM maintenance window. AI analyzes system health and
 * autonomously executes safe, non-destructive artisan commands.
 *
 * Safety Classification (from devops_commands table):
 * - GREEN: Safe to auto-run (cache clear, view clear, queue retry)
 * - YELLOW: Needs review before running (migrations, optimize)
 * - RED: Never auto-run (db:wipe, migrate:fresh, storage:link)
 *
 * The AI is made aware of ALL available artisan commands in the framework,
 * but can only auto-execute commands marked as GREEN with auto_execute=true.
 */
class AIDevOpsMaintenance extends Command
{
    protected $signature = 'devops:ai-maintenance
                            {--dry-run : Show what would be done without executing}
                            {--force : Run outside maintenance window}
                            {--skip-ai : Skip AI analysis, just run health checks}
                            {--discover : Discover and list all available artisan commands}
                            {--sync-commands : Sync new artisan commands to devops_commands table}';

    protected $description = 'AI-driven early morning DevOps maintenance (3-5 AM window)';

    private bool $dryRun = false;

    private array $executedCommands = [];

    private array $skippedCommands = [];

    private array $healthIssues = [];

    private array $healthMetrics = [];

    private array $greenCommands = [];

    private array $yellowCommands = [];

    private array $redCommands = [];

    private array $allArtisanCommands = [];

    public function handle(): int
    {
        // Handle special modes first
        if ($this->option('discover')) {
            return $this->discoverCommands();
        }

        if ($this->option('sync-commands')) {
            return $this->syncNewCommands();
        }

        $this->dryRun = $this->option('dry-run');
        $startTime = microtime(true);

        // Check maintenance window (3-5 AM) unless forced
        $currentHour = (int) date('G');
        if (! $this->option('force') && ($currentHour < 3 || $currentHour >= 5)) {
            $this->warn('Outside maintenance window (3-5 AM). Use --force to override.');

            return Command::FAILURE;
        }

        // Load command classifications from database
        $this->loadCommandClassifications();

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║     AI DevOps Maintenance - '.now()->format('Y-m-d H:i:s').'      ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        if ($this->dryRun) {
            $this->warn('🔍 DRY-RUN MODE - No commands will be executed');
            $this->newLine();
        }

        // Step 1: Collect health metrics
        $this->collectHealthMetrics();

        // Step 2: Analyze with AI (unless skipped)
        $recommendations = [];
        if (! $this->option('skip-ai')) {
            $recommendations = $this->getAIRecommendations();
        } else {
            $this->info('⏭️  Skipping AI analysis (--skip-ai flag)');
            $recommendations = $this->getBasicRecommendations();
        }

        // Step 3: Execute safe (GREEN) commands
        $this->executeRecommendations($recommendations);

        // Step 4: Send Pushover notification with summary
        $duration = round(microtime(true) - $startTime, 2);
        $this->sendNotification($duration);

        // Step 5: Log summary
        $this->displaySummary($duration);

        Log::info('AI DevOps Maintenance completed', [
            'duration' => $duration,
            'dry_run' => $this->dryRun,
            'executed' => $this->executedCommands,
            'skipped' => $this->skippedCommands,
            'issues' => count($this->healthIssues),
        ]);

        return Command::SUCCESS;
    }

    /**
     * Load command classifications from devops_commands table
     */
    private function loadCommandClassifications(): void
    {
        $commands = DB::select('SELECT command, description, safety_level, auto_execute, conditions FROM devops_commands');

        foreach ($commands as $cmd) {
            $cmdData = [
                'description' => $cmd->description,
                'auto_execute' => (bool) $cmd->auto_execute,
                'conditions' => $cmd->conditions ? json_decode($cmd->conditions, true) : null,
            ];

            switch ($cmd->safety_level) {
                case 'green':
                    $this->greenCommands[$cmd->command] = $cmdData;
                    break;
                case 'yellow':
                    $this->yellowCommands[$cmd->command] = $cmdData;
                    break;
                case 'red':
                    $this->redCommands[$cmd->command] = $cmdData;
                    break;
            }
        }

        $this->info('📦 Loaded '.count($this->greenCommands).' GREEN, '
            .count($this->yellowCommands).' YELLOW, '
            .count($this->redCommands).' RED commands from database');
    }

    /**
     * Discover all available artisan commands in the framework
     */
    private function discoverCommands(): int
    {
        $this->info('🔍 Discovering all available artisan commands...');
        $this->newLine();

        // Get all registered commands
        $commands = Artisan::all();

        $this->allArtisanCommands = [];
        foreach ($commands as $name => $command) {
            $this->allArtisanCommands[$name] = [
                'description' => $command->getDescription(),
                'hidden' => $command->isHidden(),
            ];
        }

        // Sort by name
        ksort($this->allArtisanCommands);

        // Load existing classifications
        $this->loadCommandClassifications();

        // Display commands grouped by classification
        $classified = array_merge(
            array_keys($this->greenCommands),
            array_keys($this->yellowCommands),
            array_keys($this->redCommands)
        );

        $unclassified = [];
        $classifiedList = [];

        foreach ($this->allArtisanCommands as $name => $data) {
            if ($data['hidden']) {
                continue;
            }

            if (in_array($name, $classified)) {
                $level = 'unknown';
                if (isset($this->greenCommands[$name])) {
                    $level = 'green';
                } elseif (isset($this->yellowCommands[$name])) {
                    $level = 'yellow';
                } elseif (isset($this->redCommands[$name])) {
                    $level = 'red';
                }

                $classifiedList[$name] = $level;
            } else {
                $unclassified[$name] = $data['description'];
            }
        }

        $this->info('=== CLASSIFIED COMMANDS ===');
        $this->table(
            ['Command', 'Safety Level'],
            array_map(fn ($cmd, $level) => [$cmd, strtoupper($level)], array_keys($classifiedList), array_values($classifiedList))
        );

        $this->newLine();
        $this->info('=== UNCLASSIFIED COMMANDS ('.count($unclassified).') ===');
        $this->warn('These commands need to be added to devops_commands table:');
        $this->newLine();

        foreach ($unclassified as $name => $desc) {
            $this->line("  {$name}: {$desc}");
        }

        $this->newLine();
        $this->info('Run with --sync-commands to add unclassified commands as RED (safest default)');

        return Command::SUCCESS;
    }

    /**
     * Sync new artisan commands to database with RED classification
     */
    private function syncNewCommands(): int
    {
        $this->info('🔄 Syncing new artisan commands to database...');

        // Get all registered commands
        $commands = Artisan::all();

        // Load existing
        $existing = DB::select('SELECT command FROM devops_commands');
        $existingNames = array_column($existing, 'command');

        $added = 0;
        foreach ($commands as $name => $command) {
            if ($command->isHidden()) {
                continue;
            }
            if (in_array($name, $existingNames)) {
                continue;
            }

            // Skip Laravel internal commands that are rarely useful
            $skipPrefixes = ['vendor:', 'package:', 'stub:', 'sail:'];
            $skip = false;
            foreach ($skipPrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Add as RED (never auto-execute) by default for safety
            DB::insert(
                "INSERT INTO devops_commands (command, description, safety_level, auto_execute, conditions)
                 VALUES (?, ?, 'red', FALSE, NULL)",
                [$name, $command->getDescription() ?: 'No description']
            );

            $this->line("  + Added: {$name}");
            $added++;
        }

        $this->newLine();
        $this->info("✅ Added {$added} new command(s) as RED (safe default)");
        $this->warn('Review and update safety_level in devops_commands table as needed.');

        return Command::SUCCESS;
    }

    /**
     * Collect system health metrics for AI analysis
     */
    private function collectHealthMetrics(): void
    {
        $this->info('📊 Collecting health metrics...');

        // 1. Queue health
        $failedJobs = (int) (DB::selectOne(
            'SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        )->count ?? 0);
        $pendingJobs = $this->getPendingQueueJobs();
        $this->healthMetrics['failed_jobs'] = $failedJobs;
        $this->healthMetrics['pending_jobs'] = $pendingJobs;
        $this->line("  • Failed jobs (24h): {$failedJobs}");
        $this->line("  • Pending jobs: {$pendingJobs}");

        if ($failedJobs > 0) {
            $this->healthIssues[] = "Found {$failedJobs} failed queue job(s) in the last 24 hours";
        }

        // 2. Stuck workflows
        $stuckWorkflows = DB::select(
            "SELECT COUNT(*) as count FROM workflow_runs
             WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        )[0]->count ?? 0;
        $this->healthMetrics['stuck_workflows'] = $stuckWorkflows;
        $this->line("  • Stuck workflows: {$stuckWorkflows}");

        if ($stuckWorkflows > 0) {
            $this->healthIssues[] = "Found {$stuckWorkflows} stuck workflow(s)";
        }

        // 3. Recent failures
        $recentFailures = DB::select(
            "SELECT COUNT(*) as count FROM workflow_runs
             WHERE status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )[0]->count ?? 0;
        $this->healthMetrics['recent_failures'] = $recentFailures;
        $this->line("  • Failed workflows (24h): {$recentFailures}");

        if ($recentFailures > 5) {
            $this->healthIssues[] = "High failure rate: {$recentFailures} workflow failures in 24h";
        }

        // 3b. Stuck file catalog sync runs
        // WARNING threshold: 1 hour (log only), AUTO-RESET threshold: 6 hours (safe)
        // Uses heartbeat_at for detection
        $stuckCatalogRuns = DB::select(
            "SELECT COUNT(*) as count FROM file_registry_sync_runs
             WHERE status = 'running' AND heartbeat_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )[0]->count ?? 0;
        $this->healthMetrics['stuck_catalog_runs'] = $stuckCatalogRuns;
        $this->line("  • File catalog sync runs stuck (>1h): {$stuckCatalogRuns}");

        if ($stuckCatalogRuns > 0) {
            $this->healthIssues[] = "STUCK: {$stuckCatalogRuns} file catalog sync run(s) >1 hour";
        }

        // 3c. Stuck file registry sync runs
        // WARNING: 6 hours, AUTO-RESET: 24 hours
        // Full drive scans can legitimately take hours
        $warningFileSyncs = DB::select(
            "SELECT COUNT(*) as count FROM file_registry_sync_runs
             WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
        )[0]->count ?? 0;
        $stuckFileSyncs = DB::select(
            "SELECT COUNT(*) as count FROM file_registry_sync_runs
             WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )[0]->count ?? 0;
        $this->healthMetrics['stuck_file_syncs'] = $stuckFileSyncs;
        $this->healthMetrics['warning_file_syncs'] = $warningFileSyncs;
        $this->line("  • File registry syncs - warning (>6h): {$warningFileSyncs}, stuck (>24h): {$stuckFileSyncs}");

        if ($warningFileSyncs > 0 && $warningFileSyncs > $stuckFileSyncs) {
            $this->healthIssues[] = "WARNING: {$warningFileSyncs} file registry sync(s) running >6 hours (monitoring)";
        }
        if ($stuckFileSyncs > 0) {
            $this->healthIssues[] = "STUCK: {$stuckFileSyncs} file registry sync(s) >24 hours - auto-resetting";
            if (! $this->dryRun) {
                $reset = DB::update(
                    "UPDATE file_registry_sync_runs SET status = 'failed'
                     WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                );
                $this->warn("  → Auto-reset {$reset} stale file registry sync(s) to 'failed' status");
            }
        }

        // 3d. Stuck windows sync runs
        // WARNING: 6 hours, AUTO-RESET: 24 hours
        // Network drives and large syncs can take extended time
        $warningWindowsSyncs = DB::select(
            "SELECT COUNT(*) as count FROM windows_sync_runs
             WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
        )[0]->count ?? 0;
        $stuckWindowsSyncs = DB::select(
            "SELECT COUNT(*) as count FROM windows_sync_runs
             WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )[0]->count ?? 0;
        $this->healthMetrics['stuck_windows_syncs'] = $stuckWindowsSyncs;
        $this->healthMetrics['warning_windows_syncs'] = $warningWindowsSyncs;
        $this->line("  • Windows syncs - warning (>6h): {$warningWindowsSyncs}, stuck (>24h): {$stuckWindowsSyncs}");

        if ($warningWindowsSyncs > 0 && $warningWindowsSyncs > $stuckWindowsSyncs) {
            $this->healthIssues[] = "WARNING: {$warningWindowsSyncs} windows sync(s) running >6 hours (monitoring)";
        }
        if ($stuckWindowsSyncs > 0) {
            $this->healthIssues[] = "STUCK: {$stuckWindowsSyncs} windows sync(s) >24 hours - auto-resetting";
            if (! $this->dryRun) {
                $reset = DB::update(
                    "UPDATE windows_sync_runs SET status = 'failed'
                     WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                );
                $this->warn("  → Auto-reset {$reset} stale windows sync(s) to 'failed' status");
            }
        }

        // 3e. Stuck Joplin queue jobs
        // WARNING: 2 hours, AUTO-RESET: 12 hours
        // Large notebook operations can take time, uses updated_at for heartbeat
        $warningJoplinJobs = DB::select(
            "SELECT COUNT(*) as count FROM joplin_queue_jobs
             WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        )[0]->count ?? 0;
        $stuckJoplinJobs = DB::select(
            "SELECT COUNT(*) as count FROM joplin_queue_jobs
             WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 12 HOUR)"
        )[0]->count ?? 0;
        $this->healthMetrics['stuck_joplin_jobs'] = $stuckJoplinJobs;
        $this->healthMetrics['warning_joplin_jobs'] = $warningJoplinJobs;
        $this->line("  • Joplin jobs - warning (>2h): {$warningJoplinJobs}, stuck (>12h): {$stuckJoplinJobs}");

        if ($warningJoplinJobs > 0 && $warningJoplinJobs > $stuckJoplinJobs) {
            $this->healthIssues[] = "WARNING: {$warningJoplinJobs} Joplin job(s) processing >2 hours (monitoring)";
        }
        if ($stuckJoplinJobs > 0) {
            $this->healthIssues[] = "STUCK: {$stuckJoplinJobs} Joplin job(s) >12 hours - auto-resetting";
            if (! $this->dryRun) {
                $reset = DB::update(
                    "UPDATE joplin_queue_jobs SET status = 'failed', updated_at = NOW()
                     WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 12 HOUR)"
                );
                $this->warn("  → Auto-reset {$reset} stale Joplin job(s) to 'failed' status");
            }
        }

        // 3f-3h: Broker discovery queue, email shipments, captcha queue checks removed (D1/D2: tables dropped)

        // 3i. Stuck email reply drafts
        // WARNING: 1 hour, AUTO-RESET: 6 hours
        // Draft generation should be quick, uses updated_at
        $warningEmailDrafts = DB::select(
            "SELECT COUNT(*) as count FROM email_reply_drafts
             WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )[0]->count ?? 0;
        $stuckEmailDrafts = DB::select(
            "SELECT COUNT(*) as count FROM email_reply_drafts
             WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
        )[0]->count ?? 0;
        $this->healthMetrics['stuck_email_drafts'] = $stuckEmailDrafts;
        $this->healthMetrics['warning_email_drafts'] = $warningEmailDrafts;
        $this->line("  • Email drafts - warning (>1h): {$warningEmailDrafts}, stuck (>6h): {$stuckEmailDrafts}");

        if ($warningEmailDrafts > 0 && $warningEmailDrafts > $stuckEmailDrafts) {
            $this->healthIssues[] = "WARNING: {$warningEmailDrafts} email draft(s) processing >1 hour (monitoring)";
        }
        if ($stuckEmailDrafts > 0) {
            $this->healthIssues[] = "STUCK: {$stuckEmailDrafts} email draft(s) >6 hours - auto-resetting";
            if (! $this->dryRun) {
                $reset = DB::update(
                    "UPDATE email_reply_drafts SET status = 'failed', updated_at = NOW()
                     WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)"
                );
                $this->warn("  → Auto-reset {$reset} stale email draft(s) to 'failed' status");
            }
        }

        // 3j. Phase 4: Multi-Signal Stuck Detection with Health Flags
        // Runs tiered escalation (warning → flagged → presumed_failed → hard_fail)
        // Uses heartbeat, Horizon verification, and health flags for safer auto-reset
        $this->line('');
        $this->info('📊 Phase 4 Multi-Signal Stuck Detection:');
        try {
            $multiSignalResults = $this->runMultiSignalStuckDetection();
            $this->healthMetrics['multi_signal_detection'] = $multiSignalResults;

            foreach ($multiSignalResults as $table => $summary) {
                $tableName = str_replace('_', ' ', $table);
                $this->line("  • {$tableName}: checked={$summary['checked']}, warning={$summary['warning']}, flagged={$summary['flagged']}, presumed_failed={$summary['presumed_failed']}, hard_fail={$summary['hard_fail']}, auto_reset={$summary['auto_reset']}");

                if ($summary['skipped_heartbeat'] > 0) {
                    $this->line("    → Skipped {$summary['skipped_heartbeat']} with recent heartbeat");
                }
                if ($summary['skipped_horizon'] > 0) {
                    $this->line("    → Skipped {$summary['skipped_horizon']} still in Horizon");
                }
            }

            // Add health flag dashboard data
            $flagDashboard = $this->getHealthFlagDashboard();
            $this->healthMetrics['health_flags'] = $flagDashboard;

            $totalWarning = $flagDashboard['total'][ProcessHealthFlagService::LEVEL_WARNING] ?? 0;
            $totalFlagged = $flagDashboard['total'][ProcessHealthFlagService::LEVEL_FLAGGED] ?? 0;
            $totalPresumed = $flagDashboard['total'][ProcessHealthFlagService::LEVEL_PRESUMED_FAILED] ?? 0;
            $totalHardFail = $flagDashboard['total'][ProcessHealthFlagService::LEVEL_HARD_FAIL] ?? 0;

            $this->line("  • Health flags summary: warning={$totalWarning}, flagged={$totalFlagged}, presumed_failed={$totalPresumed}, hard_fail={$totalHardFail}");

            if ($totalFlagged > 0) {
                $this->healthIssues[] = "Phase 4: {$totalFlagged} process(es) at FLAGGED level (2x threshold)";
            }
            if ($totalPresumed > 0) {
                $this->healthIssues[] = "Phase 4: {$totalPresumed} process(es) PRESUMED FAILED (4x threshold)";
            }
            if ($totalHardFail > 0) {
                $this->healthIssues[] = "Phase 4: {$totalHardFail} process(es) at HARD FAIL (8x threshold, pending auto-reset)";
            }
        } catch (Exception $e) {
            Log::warning('AIDevOpsMaintenance: Multi-signal detection failed', [
                'error' => $e->getMessage(),
            ]);
            $this->warn('  ⚠ Multi-signal detection error: '.$e->getMessage());
        }

        // 4. Horizon status (informational only - not critical for cron-based scheduler)
        $horizonStatus = 'unknown';
        try {
            $horizonProcess = trim(\Illuminate\Support\Facades\Process::timeout(5)->run([
                'pgrep',
                '-f',
                'artisan horizon$',
            ])->output());
            $horizonSystemd = trim(\Illuminate\Support\Facades\Process::timeout(5)->run([
                'systemctl',
                'is-active',
                'laravel-horizon.service',
            ])->output());
            $horizonStatus = (! empty($horizonProcess) || $horizonSystemd === 'active') ? 'running' : 'stopped';
        } catch (Exception $e) {
            $horizonStatus = 'error';
        }
        $this->healthMetrics['horizon'] = $horizonStatus;
        $this->line("  • Horizon status: {$horizonStatus}");

        // 5. Redis connectivity
        $redisOk = false;
        try {
            $redis = app('redis');
            $redis->connection()->ping();
            $redisOk = true;
        } catch (Exception $e) {
            $redisOk = false;
        }
        $this->healthMetrics['redis'] = $redisOk ? 'connected' : 'disconnected';
        $this->line('  • Redis: '.($redisOk ? 'connected' : 'disconnected'));

        if (! $redisOk) {
            $this->healthIssues[] = 'Redis connection failed';
        }

        // 6. Disk space
        $freeSpace = disk_free_space(base_path());
        $freeSpaceGB = round($freeSpace / 1024 / 1024 / 1024, 2);
        $this->healthMetrics['disk_free_gb'] = $freeSpaceGB;
        $this->line("  • Free disk space: {$freeSpaceGB} GB");

        if ($freeSpaceGB < 5) {
            $this->healthIssues[] = "Low disk space: {$freeSpaceGB} GB free";
        }

        // 7. Cache size (Redis keys)
        try {
            $redis = app('redis');
            $cacheKeys = $redis->connection()->dbsize();
            $this->healthMetrics['redis_keys'] = $cacheKeys;
            $this->line("  • Redis keys: {$cacheKeys}");
        } catch (Exception $e) {
            $this->healthMetrics['redis_keys'] = 'unknown';
        }

        // 8. Log file sizes
        $logDir = storage_path('logs');
        $logSize = 0;
        if (is_dir($logDir)) {
            foreach (glob($logDir.'/*.log') as $file) {
                $logSize += filesize($file);
            }
        }
        $logSizeMB = round($logSize / 1024 / 1024, 2);
        $this->healthMetrics['log_size_mb'] = $logSizeMB;
        $this->line("  • Log files size: {$logSizeMB} MB");

        if ($logSizeMB > 100) {
            $this->healthIssues[] = "Large log files: {$logSizeMB} MB";
        }

        // 9. YouTube Transcript Method Health
        try {
            $youtubeApi = new \App\Services\YouTubeApiService;
            $transcriptHealth = $youtubeApi->getTranscriptMethodsHealth();
            $this->healthMetrics['youtube_transcript'] = $transcriptHealth;

            $this->line('  • YouTube Transcript Methods:');
            $cooldownMethods = [];
            $lowHealthMethods = [];

            foreach ($transcriptHealth as $method => $data) {
                $status = $data['in_cooldown'] ? '🔴' : '🟢';
                $calls = $data['total_calls'];
                $rate = $data['success_rate'];
                $this->line("      {$status} {$method}: {$rate} ({$calls} calls)");

                if ($data['in_cooldown']) {
                    $cooldownMethods[] = $method;
                }
                // Flag methods with <50% success rate and >5 calls as concerning
                $numericRate = (float) str_replace('%', '', $rate);
                if ($calls > 5 && $numericRate < 50) {
                    $lowHealthMethods[] = "{$method} ({$rate})";
                }
            }

            if (! empty($cooldownMethods)) {
                $this->healthIssues[] = 'YouTube methods in cooldown: '.implode(', ', $cooldownMethods);
            }
            if (! empty($lowHealthMethods)) {
                $this->healthIssues[] = 'YouTube methods with low success: '.implode(', ', $lowHealthMethods);
            }
        } catch (\Exception $e) {
            $this->line('      ⚠️  Could not retrieve YouTube transcript health: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('📋 Found '.count($this->healthIssues).' issue(s)');
        foreach ($this->healthIssues as $issue) {
            $this->warn("  ⚠️  {$issue}");
        }
        $this->newLine();
    }

    private function getPendingQueueJobs(): int
    {
        if (config('queue.default') === 'redis') {
            try {
                $queueNames = array_values(array_unique(array_filter([
                    config('queue.connections.redis.queue', env('REDIS_QUEUE', 'default')),
                    'high',
                    'default',
                    'low',
                    'workflow',
                    'long-running',
                    'speculative',
                ])));

                $total = 0;
                foreach ($queueNames as $queueName) {
                    $total += (int) (Redis::llen("queues:{$queueName}") ?? 0);
                }

                return $total;
            } catch (\Throwable $e) {
                Log::debug('AIDevOpsMaintenance: Redis queue depth lookup failed', ['error' => $e->getMessage()]);

                return 0;
            }
        }

        return (int) (DB::selectOne('SELECT COUNT(*) as count FROM jobs')->count ?? 0);
    }

    /**
     * Get AI recommendations based on health metrics
     */
    private function getAIRecommendations(): array
    {
        $this->info('🤖 Consulting AI for maintenance recommendations...');

        try {
            $aiService = app(AIService::class);

            $prompt = $this->buildAIPrompt();

            $result = $aiService->process($prompt, [
                'max_tokens' => 1000,
                'factual_mode' => true, // Use factual mode for consistent recommendations
            ]);

            if (! empty($result['error'])) {
                $this->warn("  AI analysis failed: {$result['error']}");
                $this->info('  Falling back to basic recommendations...');

                return $this->getBasicRecommendations();
            }

            $content = $result['content'] ?? '';
            $recommendations = $this->parseAIRecommendations($content);

            $this->info('  AI suggested '.count($recommendations).' command(s)');
            $this->newLine();

            return $recommendations;

        } catch (Exception $e) {
            $this->warn('  AI analysis error: '.$e->getMessage());
            $this->info('  Falling back to basic recommendations...');

            return $this->getBasicRecommendations();
        }
    }

    /**
     * Build the AI prompt for maintenance analysis - now includes ALL available commands
     */
    private function buildAIPrompt(): string
    {
        $metrics = json_encode($this->healthMetrics, JSON_PRETTY_PRINT);
        $issues = ! empty($this->healthIssues) ? implode("\n- ", $this->healthIssues) : 'No issues detected';

        // Build GREEN commands list with conditions
        $greenList = [];
        foreach ($this->greenCommands as $cmd => $data) {
            $conditionHint = '';
            if ($data['conditions']) {
                $conditionHint = ' [when: '.json_encode($data['conditions']).']';
            }
            $greenList[] = "{$cmd}: {$data['description']}{$conditionHint}";
        }
        $greenCommandsStr = implode("\n- ", $greenList);

        // Build YELLOW commands list (for awareness, not execution)
        $yellowList = [];
        foreach ($this->yellowCommands as $cmd => $data) {
            $yellowList[] = "{$cmd}: {$data['description']}";
        }
        $yellowCommandsStr = implode("\n- ", $yellowList);

        // Get count of all available commands
        $totalCommands = count($this->greenCommands) + count($this->yellowCommands) + count($this->redCommands);

        return <<<PROMPT
You are a conservative DevOps AI assistant for a Laravel automation framework.
Analyze the following system health metrics and recommend ONLY safe maintenance commands.

CRITICAL SAFETY RULES:
1. You can ONLY recommend commands from the GREEN (safe) list below
2. NEVER recommend YELLOW or RED commands - they require human approval
3. Be CONSERVATIVE - if unsure, don't recommend
4. Check the conditions for each command and only recommend if conditions are met
5. For queue:retry, only recommend if failed_jobs > 0
6. For cache:clear, only recommend if there are cache-related issues

CURRENT SYSTEM HEALTH:
{$metrics}

IDENTIFIED ISSUES:
- {$issues}

═══════════════════════════════════════════════════════════════════════════════
AVAILABLE COMMANDS (Total: {$totalCommands} registered in devops_commands table)
═══════════════════════════════════════════════════════════════════════════════

GREEN - SAFE TO AUTO-EXECUTE (you can recommend these):
- {$greenCommandsStr}

YELLOW - NEEDS HUMAN REVIEW (DO NOT RECOMMEND):
- {$yellowCommandsStr}

RED - NEVER AUTO-EXECUTE (DO NOT RECOMMEND):
(Destructive commands like migrate:fresh, db:wipe, etc.)

═══════════════════════════════════════════════════════════════════════════════

Respond with a JSON array of commands to execute. Example:
["cache:clear", "queue:retry all"]

If no maintenance is needed, respond with an empty array: []

IMPORTANT: Be productive but safe. Only include GREEN commands that address actual issues.
The human will be notified of exactly which commands you ran via Pushover.
PROMPT;
    }

    /**
     * Parse AI response into command list
     */
    private function parseAIRecommendations(string $content): array
    {
        // Try to extract JSON array from response
        if (preg_match('/\[.*?\]/s', $content, $matches)) {
            $commands = json_decode($matches[0], true);
            if (is_array($commands)) {
                // Filter to only GREEN commands with auto_execute=true
                return array_filter($commands, function ($cmd) {
                    return isset($this->greenCommands[$cmd]) && $this->greenCommands[$cmd]['auto_execute'];
                });
            }
        }

        // Fallback: look for command patterns in text
        $recommendations = [];
        foreach ($this->greenCommands as $cmd => $data) {
            if (! $data['auto_execute']) {
                continue;
            }
            if (stripos($content, $cmd) !== false) {
                $recommendations[] = $cmd;
            }
        }

        return array_unique($recommendations);
    }

    /**
     * Get basic recommendations without AI
     */
    private function getBasicRecommendations(): array
    {
        $recommendations = [];

        // Check each GREEN command's conditions
        foreach ($this->greenCommands as $cmd => $data) {
            if (! $data['auto_execute']) {
                continue;
            }

            $conditions = $data['conditions'];
            if (! $conditions) {
                continue;
            }

            // Evaluate conditions
            $meetsConditions = $this->evaluateConditions($conditions);
            if ($meetsConditions) {
                $recommendations[] = $cmd;
            }
        }

        return $recommendations;
    }

    /**
     * Evaluate conditions for a command
     */
    private function evaluateConditions(array $conditions): bool
    {
        foreach ($conditions as $metric => $threshold) {
            // Parse metric_operator format (e.g., failed_jobs_gt)
            if (preg_match('/^(.+)_(gt|gte|lt|lte|eq)$/', $metric, $matches)) {
                $metricName = $matches[1];
                $operator = $matches[2];
                $value = $this->healthMetrics[$metricName] ?? null;

                if ($value === null) {
                    return false;
                }

                switch ($operator) {
                    case 'gt': if (! ($value > $threshold)) {
                        return false;
                    } break;
                    case 'gte': if (! ($value >= $threshold)) {
                        return false;
                    } break;
                    case 'lt': if (! ($value < $threshold)) {
                        return false;
                    } break;
                    case 'lte': if (! ($value <= $threshold)) {
                        return false;
                    } break;
                    case 'eq': if ($value != $threshold) {
                        return false;
                    } break;
                }
            } elseif (isset($this->healthMetrics[$metric])) {
                // Simple equality check
                if ($this->healthMetrics[$metric] != $threshold) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Execute recommended commands (GREEN with auto_execute only)
     */
    private function executeRecommendations(array $recommendations): void
    {
        if (empty($recommendations)) {
            $this->info('✅ No maintenance actions needed');

            return;
        }

        $this->info('🔧 Executing maintenance commands...');
        $this->newLine();

        foreach ($recommendations as $command) {
            $this->executeCommand($command);
        }

        $this->newLine();
    }

    /**
     * Execute a single command with safety checks
     */
    private function executeCommand(string $command): void
    {
        // Safety check: only GREEN commands with auto_execute
        if (! isset($this->greenCommands[$command]) || ! $this->greenCommands[$command]['auto_execute']) {
            $safety = 'UNKNOWN';
            if (isset($this->greenCommands[$command])) {
                $safety = 'GREEN (auto_execute=false)';
            } elseif (isset($this->yellowCommands[$command])) {
                $safety = 'YELLOW';
            } elseif (isset($this->redCommands[$command])) {
                $safety = 'RED';
            }

            $this->warn("  ⛔ SKIPPED [{$safety}]: {$command}");
            $this->skippedCommands[] = [
                'command' => $command,
                'reason' => "Not auto-executable ({$safety})",
            ];

            return;
        }

        $description = $this->greenCommands[$command]['description'];

        if ($this->dryRun) {
            $this->line("  🔍 [DRY-RUN] Would execute: {$command}");
            $this->executedCommands[] = [
                'command' => $command,
                'status' => 'dry-run',
                'description' => $description,
            ];

            return;
        }

        $this->line("  ▶️  Executing: {$command}");

        try {
            $startTime = microtime(true);

            // Parse command and arguments
            $parts = explode(' ', $command, 2);
            $artisanCommand = $parts[0];
            $args = [];

            // Handle special argument patterns
            if (isset($parts[1])) {
                if ($parts[1] === 'all') {
                    // For queue:retry all
                    $args = ['id' => 'all'];
                } elseif (str_starts_with($parts[1], '--')) {
                    // For --option style
                    $args = [ltrim($parts[1], '-') => true];
                }
            }

            // Execute via Artisan
            $exitCode = Artisan::call($artisanCommand, $args);
            $output = Artisan::output();
            $duration = round((microtime(true) - $startTime) * 1000);

            // Update execution stats in database
            DB::update(
                'UPDATE devops_commands SET last_executed_at = NOW(), execution_count = execution_count + 1 WHERE command = ?',
                [$command]
            );

            if ($exitCode === 0) {
                $this->info("     ✅ Success ({$duration}ms)");
                $this->executedCommands[] = [
                    'command' => $command,
                    'status' => 'success',
                    'duration_ms' => $duration,
                    'description' => $description,
                ];
            } else {
                $this->error("     ❌ Failed (exit code: {$exitCode})");
                $this->executedCommands[] = [
                    'command' => $command,
                    'status' => 'failed',
                    'exit_code' => $exitCode,
                    'output' => substr($output, 0, 200),
                    'description' => $description,
                ];
            }

        } catch (Exception $e) {
            $this->error('     ❌ Error: '.$e->getMessage());
            $this->executedCommands[] = [
                'command' => $command,
                'status' => 'error',
                'error' => $e->getMessage(),
                'description' => $description,
            ];
        }
    }

    /**
     * Send Pushover notification with maintenance summary
     */
    private function sendNotification(float $duration): void
    {
        // Skip standalone Pushover — consolidated into ops:daily-report
        try {
            $dailyReport = DB::selectOne(
                "SELECT enabled FROM scheduled_jobs WHERE name = 'daily_report' AND enabled = 1"
            );
            if ($dailyReport) {
                $this->info('Notification suppressed (consolidated into daily report)');

                return;
            }
        } catch (\Throwable) {
        }

        $this->info('Sending Pushover notification...');

        $title = $this->dryRun
            ? 'AI DevOps Maintenance [DRY-RUN]'
            : 'AI DevOps Maintenance';

        $message = $this->buildNotificationMessage($duration);

        try {
            $controller = new NotificationController;
            $success = $controller->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => $title,
                'message' => $message,
                'priority' => 0,
                'format_type' => 'html',
            ]);

            if ($success) {
                $this->info('  ✅ Notification sent');
            } else {
                $this->warn('  ⚠️ Notification may have failed');
            }

        } catch (Exception $e) {
            $this->error('  ❌ Notification error: '.$e->getMessage());
        }
    }

    /**
     * Build notification message with executed commands
     */
    private function buildNotificationMessage(float $duration): string
    {
        $lines = [];
        $lines[] = '<b>Time:</b> '.now()->format('H:i:s');
        $lines[] = "<b>Duration:</b> {$duration}s";
        $lines[] = '';

        // Health issues found
        if (! empty($this->healthIssues)) {
            $lines[] = '<b>Issues Found:</b>';
            foreach ($this->healthIssues as $issue) {
                $lines[] = "• {$issue}";
            }
            $lines[] = '';
        }

        // Commands executed - EXPLICIT LIST
        if (! empty($this->executedCommands)) {
            $lines[] = '<b>Commands Executed:</b>';
            foreach ($this->executedCommands as $cmd) {
                $status = match ($cmd['status']) {
                    'success' => '✅',
                    'dry-run' => '🔍',
                    'failed' => '❌',
                    'error' => '⚠️',
                    default => '•',
                };
                // IMPORTANT: Show exact command that was run
                $lines[] = "{$status} <font color=\"#00BFFF\">php artisan {$cmd['command']}</font>";
            }
            $lines[] = '';
        } else {
            $lines[] = '✨ No commands needed';
            $lines[] = '';
        }

        // Skipped commands
        if (! empty($this->skippedCommands)) {
            $lines[] = '<b>Skipped (safety):</b>';
            foreach ($this->skippedCommands as $cmd) {
                $lines[] = "⛔ {$cmd['command']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Display summary to console
     */
    private function displaySummary(float $duration): void
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                    MAINTENANCE SUMMARY                     ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line("  Duration: {$duration}s");
        $this->line('  Issues found: '.count($this->healthIssues));
        $this->line('  Commands executed: '.count($this->executedCommands));
        $this->line('  Commands skipped: '.count($this->skippedCommands));

        $successCount = count(array_filter($this->executedCommands, fn ($c) => $c['status'] === 'success'));
        $failCount = count(array_filter($this->executedCommands, fn ($c) => in_array($c['status'], ['failed', 'error'])));

        if ($successCount > 0) {
            $this->info("  ✅ {$successCount} command(s) succeeded");
        }
        if ($failCount > 0) {
            $this->error("  ❌ {$failCount} command(s) failed");
        }
    }

    /**
     * Phase 4: Multi-Signal Verification for Stuck Process Detection
     *
     * Uses tiered escalation with multiple verification signals before auto-reset:
     * 1. Time threshold checks (1x=warning, 2x=flagged, 4x=presumed_failed, 8x=hard_fail)
     * 2. Horizon queue verification (for queue jobs)
     * 3. Heartbeat staleness (when available)
     * 4. Health flag escalation tracking
     *
     * Only auto-resets when ALL signals confirm the process is truly stuck.
     *
     * @param  string  $tableName  Table to check
     * @param  string  $statusColumn  Column holding the running status
     * @param  string  $statusValue  Value indicating running status
     * @param  string  $activityColumn  Column to check for last activity
     * @param  bool  $checkHorizon  Whether to verify Horizon queue status
     * @return array Summary of actions taken
     */
    private function processStuckRecordsWithMultiSignal(
        string $tableName,
        string $statusColumn,
        string $statusValue,
        string $activityColumn,
        bool $checkHorizon = false
    ): array {
        $flagService = app(ProcessHealthFlagService::class);
        $summary = [
            'checked' => 0,
            'warning' => 0,
            'flagged' => 0,
            'presumed_failed' => 0,
            'hard_fail' => 0,
            'auto_reset' => 0,
            'skipped_horizon' => 0,
            'skipped_heartbeat' => 0,
        ];

        // Determine which columns to select (heartbeat_at may not exist on all tables)
        $columns = ['id', $activityColumn];
        $hasHeartbeatColumn = $this->tableHasColumn($tableName, 'heartbeat_at');
        if ($hasHeartbeatColumn) {
            $columns[] = 'heartbeat_at';
        }

        // Get all records in running status
        $records = DB::table($tableName)
            ->where($statusColumn, $statusValue)
            ->get($columns);

        foreach ($records as $record) {
            $summary['checked']++;

            $activityTime = $record->{$activityColumn};
            if (! $activityTime) {
                continue;
            }

            $minutesSinceActivity = (int) round((time() - strtotime($activityTime)) / 60);

            // Check heartbeat if available (more recent = still alive)
            $hasRecentHeartbeat = false;
            if ($hasHeartbeatColumn && ! empty($record->heartbeat_at)) {
                $minutesSinceHeartbeat = (int) round((time() - strtotime($record->heartbeat_at)) / 60);
                // If heartbeat is recent (< base threshold), skip flagging
                $baseThreshold = ProcessHealthFlagService::BASE_THRESHOLDS[$tableName] ?? 360;
                if ($minutesSinceHeartbeat < $baseThreshold) {
                    $hasRecentHeartbeat = true;
                    $summary['skipped_heartbeat']++;
                    Log::debug('AIDevOpsMaintenance: Record has recent heartbeat, skipping flag', [
                        'table' => $tableName,
                        'record_id' => $record->id,
                        'minutes_since_heartbeat' => $minutesSinceHeartbeat,
                    ]);

                    continue;
                }
            }

            // Process through tiered flag system
            $result = $flagService->flagOrEscalate($tableName, $record->id, $minutesSinceActivity);

            if ($result['flag_level']) {
                $summary[$result['flag_level']]++;

                // If hard_fail level, attempt auto-reset
                if ($result['flag_level'] === ProcessHealthFlagService::LEVEL_HARD_FAIL) {
                    // Additional verification for queue jobs
                    if ($checkHorizon && $this->isJobActiveInHorizon($record->id)) {
                        $summary['skipped_horizon']++;
                        Log::info('AIDevOpsMaintenance: Hard fail record still in Horizon, skipping reset', [
                            'table' => $tableName,
                            'record_id' => $record->id,
                        ]);

                        continue;
                    }

                    if (! $this->dryRun) {
                        // All signals confirm stuck - execute auto-reset
                        $this->autoResetStuckRecord($tableName, $record->id, $statusColumn);
                        $flagService->clearFlag($tableName, $record->id, 'auto_reset', 'Reset by multi-signal verification');
                        $summary['auto_reset']++;

                        Log::warning('AIDevOpsMaintenance: Auto-reset stuck record (multi-signal verified)', [
                            'table' => $tableName,
                            'record_id' => $record->id,
                            'minutes_stuck' => $minutesSinceActivity,
                        ]);
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Auto-reset a stuck record to failed status
     *
     * @param  string  $tableName  Table containing the record
     * @param  int  $recordId  Record ID to reset
     * @param  string  $statusColumn  Status column name
     */
    private function autoResetStuckRecord(string $tableName, int $recordId, string $statusColumn): void
    {
        DB::table($tableName)
            ->where('id', $recordId)
            ->update([
                $statusColumn => 'failed',
                'updated_at' => now(),
            ]);
    }

    /**
     * Run Phase 4 multi-signal stuck detection for all process types
     *
     * Called during health checks to apply tiered escalation.
     *
     * @return array Summary of all process type checks
     */
    private function runMultiSignalStuckDetection(): array
    {
        $allSummaries = [];

        // File registry sync runs (command-based, has heartbeat)
        $allSummaries['file_registry_sync_runs'] = $this->processStuckRecordsWithMultiSignal(
            'file_registry_sync_runs',
            'status',
            'running',
            'started_at',
            false
        );

        return $allSummaries;
    }

    /**
     * Get health flag dashboard data
     *
     * @return array Flag counts by level and table
     */
    private function getHealthFlagDashboard(): array
    {
        $flagService = app(ProcessHealthFlagService::class);

        return [
            'total' => $flagService->getFlagCountsByLevel(),
            'by_table' => [
                'file_registry_sync_runs' => $flagService->getFlagCountsByLevel('file_registry_sync_runs'),
            ],
            'hard_fail_pending' => count($flagService->getHardFailFlags()),
        ];
    }

    /**
     * Check if a table has a specific column
     *
     * @param  string  $tableName  Table name
     * @param  string  $columnName  Column name
     * @return bool True if column exists
     */
    private function tableHasColumn(string $tableName, string $columnName): bool
    {
        static $cache = [];
        $cacheKey = "{$tableName}.{$columnName}";

        if (! isset($cache[$cacheKey])) {
            $cache[$cacheKey] = \Illuminate\Support\Facades\Schema::hasColumn($tableName, $columnName);
        }

        return $cache[$cacheKey];
    }
}
