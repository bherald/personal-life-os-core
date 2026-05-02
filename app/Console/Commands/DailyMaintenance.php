<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DailyMaintenance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:daily {--dry-run : Run in dry-run mode without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run daily maintenance tasks: cleanup stuck workflows, old data, logs, and optimize database';

    private bool $dryRun = false;

    private array $stats = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->dryRun = $this->option('dry-run');

        $startTime = microtime(true);

        $this->info('=== Daily Maintenance Started ===');
        $this->info('Time: '.now()->toDateTimeString());

        if ($this->dryRun) {
            $this->warn('Running in DRY-RUN mode - no changes will be made');
        }

        $this->newLine();

        // Run maintenance tasks
        $this->cleanStuckWorkflows();
        $this->cleanOldFailedJobs();
        $this->cleanOldWorkflowRuns();
        $this->cleanOrphanedData();
        $this->rotateLogs();
        $this->optimizeDatabase();
        // Backups moved to standalone backup.sh (Linux cron, 11 PM nightly).
        // Isolated from PHP/Laravel so backups run even when the app is broken.
        // $this->backupDatabases();
        $this->checkSystemHealth();

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('=== Maintenance Summary ===');
        foreach ($this->stats as $task => $count) {
            $this->line("  {$task}: {$count}");
        }
        $this->info("Duration: {$duration}s");

        // Log maintenance run
        Log::info('Daily maintenance completed', [
            'duration' => $duration,
            'dry_run' => $this->dryRun,
            'stats' => $this->stats,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Clean up workflows stuck in running state
     *
     * Note: Threshold increased to 6 hours to accommodate long-running
     * workflows like YouTube transcript processing which can take 4-5 hours
     * when processing large playlists with rate limiting delays.
     */
    private function cleanStuckWorkflows(): void
    {
        $this->info('Checking for stuck workflows...');

        // 6 hours threshold - allows for long-running YouTube/transcript workflows
        $threshold = now()->subHours(6);

        $sql = 'SELECT id, workflow_id, started_at FROM workflow_runs WHERE status = ? AND started_at < ?';
        $stuckWorkflows = DB::select($sql, ['running', $threshold]);

        $count = count($stuckWorkflows);

        if ($count > 0) {
            $this->warn("  Found {$count} stuck workflow(s)");

            foreach ($stuckWorkflows as $run) {
                $sql = 'SELECT name FROM workflows WHERE id = ? LIMIT 1';
                $result = DB::select($sql, [$run->workflow_id]);
                $workflowName = $result[0]->name ?? 'Unknown';

                $this->line("    - Run #{$run->id} ({$workflowName}) started {$run->started_at}");

                if (! $this->dryRun) {
                    $sql = 'UPDATE workflow_runs SET status = ?, completed_at = ?, error_message = ? WHERE id = ?';
                    DB::update($sql, [
                        'failed',
                        now(),
                        'Workflow stuck in running state - auto-failed by maintenance job',
                        $run->id,
                    ]);
                }
            }

            Log::warning('Cleaned stuck workflows', ['count' => $count, 'dry_run' => $this->dryRun]);
        } else {
            $this->line('  ✓ No stuck workflows found');
        }

        $this->stats['Stuck workflows cleaned'] = $count;
    }

    /**
     * Clean up old failed jobs
     */
    private function cleanOldFailedJobs(): void
    {
        $this->info('Cleaning old failed jobs...');

        $threshold = now()->subDays(30);

        $sql = 'SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at < ?';
        $count = DB::select($sql, [$threshold])[0]->count ?? 0;

        if ($count > 0) {
            $this->line("  Found {$count} old failed job(s) to clean");

            if (! $this->dryRun) {
                $sql = 'DELETE FROM failed_jobs WHERE failed_at < ?';
                DB::delete($sql, [$threshold]);
            }
        } else {
            $this->line('  ✓ No old failed jobs to clean');
        }

        $this->stats['Old failed jobs deleted'] = $count;
    }

    /**
     * Clean up old workflow runs
     */
    private function cleanOldWorkflowRuns(): void
    {
        $this->info('Cleaning old workflow runs...');

        $threshold = now()->subDays(90);

        $sql = 'SELECT id FROM workflow_runs WHERE started_at < ?';
        $oldRunsResults = DB::select($sql, [$threshold]);
        $oldRuns = array_column($oldRunsResults, 'id');

        $count = count($oldRuns);

        if ($count > 0) {
            $this->line("  Found {$count} old workflow run(s) to clean");

            if (! $this->dryRun) {
                // Delete related outputs first
                $placeholders = implode(',', array_fill(0, count($oldRuns), '?'));
                $sql = "DELETE FROM workflow_run_outputs WHERE run_id IN ({$placeholders})";
                $outputsDeleted = DB::delete($sql, $oldRuns);

                // Delete related inputs
                $sql = "DELETE FROM workflow_run_inputs WHERE run_id IN ({$placeholders})";
                $inputsDeleted = DB::delete($sql, $oldRuns);

                // Delete the runs
                $sql = "DELETE FROM workflow_runs WHERE id IN ({$placeholders})";
                DB::delete($sql, $oldRuns);

                $this->line("    - Deleted {$outputsDeleted} outputs, {$inputsDeleted} inputs");
            }
        } else {
            $this->line('  ✓ No old workflow runs to clean');
        }

        $this->stats['Old workflow runs deleted'] = $count;
    }

    /**
     * Clean orphaned workflow data
     */
    private function cleanOrphanedData(): void
    {
        $this->info('Cleaning orphaned workflow data...');

        // Check for orphaned outputs using raw SQL
        $sql = 'SELECT COUNT(*) as count FROM workflow_run_outputs
                LEFT JOIN workflow_runs ON workflow_run_outputs.run_id = workflow_runs.id
                WHERE workflow_runs.id IS NULL';
        $orphanedOutputs = DB::select($sql)[0]->count ?? 0;

        if ($orphanedOutputs > 0) {
            $this->line("  Found {$orphanedOutputs} orphaned output(s)");

            if (! $this->dryRun) {
                $sql = 'DELETE workflow_run_outputs FROM workflow_run_outputs
                        LEFT JOIN workflow_runs ON workflow_run_outputs.run_id = workflow_runs.id
                        WHERE workflow_runs.id IS NULL';
                DB::delete($sql);
            }
        } else {
            $this->line('  ✓ No orphaned outputs');
        }

        // Check for orphaned inputs using raw SQL
        $sql = 'SELECT COUNT(*) as count FROM workflow_run_inputs
                LEFT JOIN workflow_runs ON workflow_run_inputs.run_id = workflow_runs.id
                WHERE workflow_runs.id IS NULL';
        $orphanedInputs = DB::select($sql)[0]->count ?? 0;

        if ($orphanedInputs > 0) {
            $this->line("  Found {$orphanedInputs} orphaned input(s)");

            if (! $this->dryRun) {
                $sql = 'DELETE workflow_run_inputs FROM workflow_run_inputs
                        LEFT JOIN workflow_runs ON workflow_run_inputs.run_id = workflow_runs.id
                        WHERE workflow_runs.id IS NULL';
                DB::delete($sql);
            }
        } else {
            $this->line('  ✓ No orphaned inputs');
        }

        $this->stats['Orphaned outputs deleted'] = $orphanedOutputs;
        $this->stats['Orphaned inputs deleted'] = $orphanedInputs;
    }

    /**
     * Rotate large log files
     */
    private function rotateLogs(): void
    {
        $this->info('Checking log files...');

        $logPath = storage_path('logs');
        $maxSize = 10 * 1024 * 1024; // 10MB
        $rotatedCount = 0;

        if (! File::exists($logPath)) {
            $this->line('  ✓ No logs directory');
            $this->stats['Logs rotated'] = 0;

            return;
        }

        $logFiles = File::files($logPath);

        foreach ($logFiles as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            $size = $file->getSize();

            if ($size > $maxSize) {
                $sizeMB = round($size / 1024 / 1024, 2);
                $this->line("  Found large log: {$file->getFilename()} ({$sizeMB}MB)");

                if (! $this->dryRun) {
                    $archiveName = $file->getPath().'/'.
                                   $file->getBasename('.log').
                                   '-'.date('Y-m-d-His').'.log.archive';

                    File::move($file->getPathname(), $archiveName);
                    File::put($file->getPathname(), ''); // Create new empty log

                    $this->line('    - Archived to: '.basename($archiveName));
                }

                $rotatedCount++;
            }
        }

        if ($rotatedCount === 0) {
            $this->line('  ✓ No large log files to rotate');
        }

        $this->stats['Logs rotated'] = $rotatedCount;
    }

    /**
     * Optimize database tables
     */
    private function optimizeDatabase(): void
    {
        $this->info('Optimizing database tables...');

        $tables = [
            'workflow_runs',
            'workflow_run_outputs',
            'workflow_run_inputs',
            'workflows',
            'workflow_nodes',
            'jobs',
            'failed_jobs',
        ];

        if (! $this->dryRun) {
            foreach ($tables as $table) {
                try {
                    DB::statement("OPTIMIZE TABLE {$table}");
                } catch (\Exception $e) {
                    $this->warn("  Warning: Could not optimize {$table}: ".$e->getMessage());
                }
            }
            $this->line('  ✓ Optimized '.count($tables).' tables');
        } else {
            $this->line('  Would optimize '.count($tables).' tables');
        }

        $this->stats['Tables optimized'] = $this->dryRun ? 0 : count($tables);
    }

    /**
     * Backup databases (MySQL and PostgreSQL)
     */
    private function backupDatabases(): void
    {
        $this->info('Backing up databases...');

        $backupDir = storage_path('backups');
        $timestamp = now()->format('Y-m-d_His');

        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $mysqlBackedUp = false;
        $pgBackedUp = false;

        // MySQL Backup
        try {
            $mysqlFile = "{$backupDir}/mysql_backup_{$timestamp}.sql";

            // Use config() instead of env() for cached config compatibility
            $mysqlHost = config('database.connections.mysql.host', '127.0.0.1');
            $mysqlPort = config('database.connections.mysql.port', '3306');
            $mysqlDatabase = config('database.connections.mysql.database', 'plos');
            $mysqlUsername = config('database.connections.mysql.username', 'plos');
            $mysqlPassword = config('database.connections.mysql.password', '');

            if (! $this->dryRun) {
                $result = Process::timeout(300)
                    ->env(['MYSQL_PWD' => $mysqlPassword])
                    ->run([
                        'mysqldump',
                        '-h', $mysqlHost,
                        '-P', (string) $mysqlPort,
                        '-u', $mysqlUsername,
                        $mysqlDatabase,
                    ]);
                $backupContent = $result->successful() ? $result->output() : $result->errorOutput();
                $returnCode = $result->successful() ? 0 : 1;

                if ($returnCode === 0 && $backupContent) {
                    File::put($mysqlFile, $backupContent);
                    $sizeMB = round(strlen($backupContent) / 1024 / 1024, 2);
                    $this->line('  ✓ MySQL backup created: '.basename($mysqlFile)." ({$sizeMB}MB)");
                    $mysqlBackedUp = true;

                    // Keep only last N backups (from .env)
                    $retention = (int) config('app.backup_retention_days', 5);
                    $this->cleanOldBackups($backupDir, 'mysql_backup_*.sql', $retention);
                } else {
                    $this->warn('  ✗ MySQL backup failed');
                    Log::error('MySQL backup failed', ['error' => substr($backupContent ?? '', 0, 500)]);
                }
            } else {
                $this->line('  Would create MySQL backup: '.basename($mysqlFile));
            }
        } catch (\Exception $e) {
            $this->warn('  ✗ MySQL backup error: '.$e->getMessage());
            Log::error('MySQL backup exception', ['error' => $e->getMessage()]);
        }

        // PostgreSQL Backup
        try {
            $pgFile = "{$backupDir}/postgres_backup_{$timestamp}.sql";

            // Use pgsql_rag connection (the actual PostgreSQL connection in config)
            $pgHost = config('database.connections.pgsql_rag.host', '127.0.0.1');
            $pgPort = config('database.connections.pgsql_rag.port', '5432');
            $pgDatabase = config('database.connections.pgsql_rag.database', 'plos_rag');
            $pgUsername = config('database.connections.pgsql_rag.username', 'plos_rag');
            $pgPassword = config('database.connections.pgsql_rag.password', '');

            if (! $this->dryRun) {
                $result = Process::timeout(300)
                    ->env(['PGPASSWORD' => $pgPassword])
                    ->run([
                        'pg_dump',
                        '-h', $pgHost,
                        '-p', (string) $pgPort,
                        '-U', $pgUsername,
                        '-F', 'p',
                        '-b',
                        $pgDatabase,
                    ]);
                $backupContent = $result->successful() ? $result->output() : $result->errorOutput();
                $returnCode = $result->successful() ? 0 : 1;

                if ($returnCode === 0 && $backupContent) {
                    File::put($pgFile, $backupContent);
                    $sizeMB = round(strlen($backupContent) / 1024 / 1024, 2);
                    $this->line('  ✓ PostgreSQL backup created: '.basename($pgFile)." ({$sizeMB}MB)");
                    $pgBackedUp = true;

                    // Keep only last N backups (from .env)
                    $retention = (int) config('app.backup_retention_days', 5);
                    $this->cleanOldBackups($backupDir, 'postgres_backup_*.sql', $retention);
                } else {
                    $this->warn('  ✗ PostgreSQL backup failed');
                    Log::error('PostgreSQL backup failed', ['error' => substr($backupContent ?? '', 0, 500)]);
                }
            } else {
                $this->line('  Would create PostgreSQL backup: '.basename($pgFile));
            }
        } catch (\Exception $e) {
            $this->warn('  ✗ PostgreSQL backup error: '.$e->getMessage());
            Log::error('PostgreSQL backup exception', ['error' => $e->getMessage()]);
        }

        $this->stats['MySQL backup'] = $mysqlBackedUp ? 'Success' : 'Failed';
        $this->stats['PostgreSQL backup'] = $pgBackedUp ? 'Success' : 'Failed';
    }

    /**
     * Clean old backup files, keeping only the most recent N backups
     */
    private function cleanOldBackups(string $backupDir, string $pattern, int $keep = 5): void
    {
        $backups = glob("{$backupDir}/{$pattern}");

        if (count($backups) <= $keep) {
            return;
        }

        // Sort by modification time, newest first
        usort($backups, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Delete old backups
        $toDelete = array_slice($backups, $keep);

        foreach ($toDelete as $file) {
            File::delete($file);
            $this->line('    - Deleted old backup: '.basename($file));
        }
    }

    /**
     * Check system health
     */
    private function checkSystemHealth(): void
    {
        $this->info('Checking system health...');

        // Check Horizon, which owns queued job execution in prod.
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
        $queueRunning = $horizonProcess !== '' || $horizonSystemd === 'active';

        // Get database stats using raw SQL
        $sql = 'SELECT status, COUNT(*) as count FROM workflow_runs
                WHERE started_at > ?
                GROUP BY status';
        $statsResults = DB::select($sql, [now()->subDay()]);

        $workflowStats = [];
        foreach ($statsResults as $stat) {
            $workflowStats[$stat->status] = $stat->count;
        }

        $sql = 'SELECT COUNT(*) as count FROM workflows WHERE active = ?';
        $activeWorkflows = DB::select($sql, [true])[0]->count ?? 0;

        $health = [
            'horizon_running' => $queueRunning,
            'active_workflows' => $activeWorkflows,
            'last_24h_runs' => $workflowStats,
            'timestamp' => now()->toDateTimeString(),
        ];

        $this->line('  Horizon: '.($queueRunning ? '✓ Running' : '✗ Not Running'));
        $this->line('  Active Workflows: '.$activeWorkflows);
        $this->line('  Last 24h Runs: '.array_sum($workflowStats));

        if (! $queueRunning) {
            $this->warn('  WARNING: Horizon is not running!');
            Log::warning('Horizon not running during maintenance check');
        }

        Log::info('System health check', $health);

        $this->stats['Health check'] = 'Completed';
    }
}
