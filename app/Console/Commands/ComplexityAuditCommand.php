<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * ops:complexity-audit — Periodic complexity and health audit.
 *
 * Measures service count, table inventory, agent health, and RAG pipeline status.
 * Designed to run monthly (or on-demand) to detect drift and accumulation.
 *
 * Usage:
 *   php artisan ops:complexity-audit              # Full audit
 *   php artisan ops:complexity-audit --quick      # Tables + services only (no DB queries)
 *   php artisan ops:complexity-audit --json       # Machine-readable output
 */
class ComplexityAuditCommand extends Command
{
    protected $signature = 'ops:complexity-audit
                            {--quick : Skip DB queries (file-system analysis only)}
                            {--json : Output results as JSON}';

    protected $description = 'Audit system complexity — services, tables, agents, pipeline health';

    private array $sections = [];

    public function handle(): int
    {
        $startTime = microtime(true);
        $json = (bool) $this->option('json');
        $quick = (bool) $this->option('quick');

        if (! $json) {
            $this->info("=== PLOS Complexity Audit ===\n");
        }

        $this->auditServices();
        $this->auditCommands();

        if (! $quick) {
            $this->auditMysqlTables();
            $this->auditPostgresTables();
            $this->auditAgents();
            $this->auditScheduledJobs();
            $this->auditCodeMetrics();
        }

        $duration = round(microtime(true) - $startTime, 1);

        if ($json) {
            $this->line(json_encode([
                'sections' => $this->sections,
                'duration_s' => $duration,
                'quick_mode' => $quick,
                'audit_date' => now()->toDateString(),
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->line("--- Audit Complete ({$duration}s) ---");
        }

        $totalItems = array_sum(array_map(fn ($s) => count($s['items'] ?? []), $this->sections));
        $this->line("[ITEMS_PROCESSED:{$totalItems}]");

        return Command::SUCCESS;
    }

    private function auditServices(): void
    {
        $servicesPath = app_path('Services');
        $items = [];

        if (! is_dir($servicesPath)) {
            $this->addSection('Services', $items, ['error' => 'Services directory not found']);

            return;
        }

        // Count .php files recursively
        $files = File::allFiles($servicesPath);
        $serviceFiles = array_filter($files, fn ($f) => $f->getExtension() === 'php');
        $total = count($serviceFiles);

        // Categorize by subdirectory
        $byDir = [];
        foreach ($serviceFiles as $f) {
            $rel = $f->getRelativePath();
            $dir = $rel ?: '(root)';
            $byDir[$dir] = ($byDir[$dir] ?? 0) + 1;
        }
        ksort($byDir);

        // Count lines across all services (rough complexity proxy)
        $totalLines = 0;
        $largestFile = '';
        $largestLines = 0;
        foreach ($serviceFiles as $f) {
            $lines = count(file($f->getPathname()));
            $totalLines += $lines;
            if ($lines > $largestLines) {
                $largestLines = $lines;
                $largestFile = $f->getRelativePathname();
            }
        }

        $items = [
            'total_services' => $total,
            'total_lines' => $totalLines,
            'avg_lines' => $total > 0 ? round($totalLines / $total) : 0,
            'largest_file' => $largestFile,
            'largest_lines' => $largestLines,
            'by_directory' => $byDir,
        ];

        if (! $this->option('json')) {
            $this->line('[Services]');
            $this->line("  Total: {$total} service files ({$totalLines} lines, avg ".($total > 0 ? round($totalLines / $total) : 0).')');
            $this->line("  Largest: {$largestFile} ({$largestLines} lines)");
            foreach ($byDir as $dir => $count) {
                $this->line("    {$dir}: {$count}");
            }
        }

        $this->addSection('Services', $items);
    }

    private function auditCommands(): void
    {
        $commandsPath = app_path('Console/Commands');
        $items = [];

        if (! is_dir($commandsPath)) {
            $this->addSection('Commands', $items, ['error' => 'Commands directory not found']);

            return;
        }

        $files = File::allFiles($commandsPath);
        $commandFiles = array_filter($files, fn ($f) => $f->getExtension() === 'php');
        $total = count($commandFiles);

        $items = ['total_commands' => $total];

        if (! $this->option('json')) {
            $this->line("\n[Commands]");
            $this->line("  Total: {$total} artisan commands");
        }

        $this->addSection('Commands', $items);
    }

    private function auditMysqlTables(): void
    {
        try {
            $tables = DB::select("SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                ORDER BY TABLE_NAME");
        } catch (\Throwable $e) {
            $this->addSection('MySQL Tables', [], ['error' => $e->getMessage()]);

            return;
        }

        $total = count($tables);
        $empty = 0;
        $withData = 0;
        $totalSize = 0;

        foreach ($tables as $t) {
            $rows = (int) $t->TABLE_ROWS;
            if ($rows === 0) {
                $empty++;
            } else {
                $withData++;
            }
            $totalSize += (int) $t->DATA_LENGTH + (int) $t->INDEX_LENGTH;
        }

        $sizeMb = round($totalSize / 1024 / 1024, 1);
        $emptyPct = $total > 0 ? round(($empty / $total) * 100, 1) : 0;

        $items = [
            'total' => $total,
            'with_data' => $withData,
            'empty' => $empty,
            'empty_pct' => $emptyPct,
            'total_size_mb' => $sizeMb,
        ];

        if (! $this->option('json')) {
            $this->line("\n[MySQL Tables]");
            $this->line("  Total: {$total} (with data: {$withData}, empty: {$empty} — {$emptyPct}%)");
            $this->line("  Size: {$sizeMb} MB");
        }

        $this->addSection('MySQL Tables', $items);
    }

    private function auditPostgresTables(): void
    {
        try {
            $tables = DB::connection('pgsql_rag')->select("
                SELECT schemaname, tablename,
                       pg_total_relation_size(format('%I.%I', schemaname, tablename)) as total_size
                FROM pg_tables
                WHERE schemaname = 'public'
                ORDER BY tablename
            ");
        } catch (\Throwable) {
            // Fallback: just count tables without row counts (subquery may fail on some tables)
            try {
                $tables = DB::connection('pgsql_rag')->select("
                    SELECT tablename
                    FROM pg_tables
                    WHERE schemaname = 'public'
                    ORDER BY tablename
                ");
                $total = count($tables);
                $items = ['total' => $total, 'note' => 'row counts unavailable'];

                if (! $this->option('json')) {
                    $this->line("\n[PostgreSQL Tables]");
                    $this->line("  Total: {$total} tables (row counts unavailable)");
                }

                $this->addSection('PostgreSQL Tables', $items);

                return;
            } catch (\Throwable $e) {
                $this->addSection('PostgreSQL Tables', [], ['error' => $e->getMessage()]);

                return;
            }
        }

        $total = count($tables);
        $totalSize = 0;
        foreach ($tables as $t) {
            $totalSize += (int) ($t->total_size ?? 0);
        }
        $sizeMb = round($totalSize / 1024 / 1024, 1);

        $items = [
            'total' => $total,
            'total_size_mb' => $sizeMb,
        ];

        if (! $this->option('json')) {
            $this->line("\n[PostgreSQL Tables]");
            $this->line("  Total: {$total} tables ({$sizeMb} MB)");
        }

        $this->addSection('PostgreSQL Tables', $items);
    }

    private function auditAgents(): void
    {
        try {
            $agents = DB::select("
                SELECT
                    aj.name,
                    aj.is_enabled,
                    (SELECT COUNT(*) FROM agent_sessions s WHERE s.agent_name = aj.name) as total_sessions,
                    (SELECT COUNT(*) FROM agent_sessions s WHERE s.agent_name = aj.name AND s.status = 'completed') as completed,
                    (SELECT COUNT(*) FROM agent_sessions s WHERE s.agent_name = aj.name AND s.status = 'failed') as failed,
                    (SELECT MAX(s.created_at) FROM agent_sessions s WHERE s.agent_name = aj.name) as last_run
                FROM (SELECT DISTINCT agent_name as name, 1 as is_enabled FROM agent_sessions) aj
                ORDER BY aj.name
            ");
        } catch (\Throwable $e) {
            $this->addSection('Agents', [], ['error' => $e->getMessage()]);

            return;
        }

        $total = count($agents);
        $totalSessions = 0;
        $totalFailed = 0;

        foreach ($agents as $a) {
            $totalSessions += (int) $a->total_sessions;
            $totalFailed += (int) $a->failed;
        }

        // Review queue
        try {
            $pendingReviews = DB::select("SELECT COUNT(*) as cnt FROM agent_review_queue WHERE status = 'pending'");
            $reviewCount = (int) ($pendingReviews[0]->cnt ?? 0);
        } catch (\Throwable) {
            $reviewCount = -1;
        }

        $items = [
            'distinct_agents' => $total,
            'total_sessions' => $totalSessions,
            'total_failed' => $totalFailed,
            'pending_reviews' => $reviewCount,
        ];

        if (! $this->option('json')) {
            $this->line("\n[Agents]");
            $this->line("  Distinct agents: {$total}");
            $this->line("  Total sessions: {$totalSessions} (failed: {$totalFailed})");
            if ($reviewCount >= 0) {
                $this->line("  Pending reviews: {$reviewCount}");
            }
        }

        $this->addSection('Agents', $items);
    }

    private function auditScheduledJobs(): void
    {
        try {
            $jobs = DB::select('
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled,
                    SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) as disabled
                FROM scheduled_jobs
            ');
        } catch (\Throwable $e) {
            $this->addSection('Scheduled Jobs', [], ['error' => $e->getMessage()]);

            return;
        }

        $row = $jobs[0];
        $items = [
            'total' => (int) $row->total,
            'enabled' => (int) $row->enabled,
            'disabled' => (int) $row->disabled,
        ];

        // Recent run stats (last 24h)
        try {
            $runs = DB::select("
                SELECT
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM scheduled_job_runs
                WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $runRow = $runs[0];
            $items['runs_24h'] = (int) $runRow->total_runs;
            $items['runs_completed_24h'] = (int) $runRow->completed;
            $items['runs_failed_24h'] = (int) $runRow->failed;
        } catch (\Throwable) {
            // Non-critical
        }

        if (! $this->option('json')) {
            $this->line("\n[Scheduled Jobs]");
            $this->line("  Total: {$items['total']} (enabled: {$items['enabled']}, disabled: {$items['disabled']})");
            if (isset($items['runs_24h'])) {
                $this->line("  Last 24h: {$items['runs_24h']} runs (completed: {$items['runs_completed_24h']}, failed: {$items['runs_failed_24h']})");
            }
        }

        $this->addSection('Scheduled Jobs', $items);
    }

    private function auditCodeMetrics(): void
    {
        // Count routes
        $routeCount = 0;
        try {
            \Illuminate\Support\Facades\Artisan::call('route:list', ['--json' => true]);
            $routes = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
            $routeCount = is_array($routes) ? count($routes) : 0;
        } catch (\Throwable) {
            // Non-critical
        }

        // Count migrations
        $migrationsPath = database_path('migrations');
        $migrationCount = is_dir($migrationsPath) ? count(File::files($migrationsPath)) : 0;

        // Config files
        $configPath = config_path();
        $configCount = is_dir($configPath) ? count(File::files($configPath)) : 0;

        // Skill files (agents)
        $skillsPath = base_path(config('agents.skills_path', 'resources/agents/skills'));
        $skillCount = 0;
        if (is_dir($skillsPath)) {
            $dirs = File::directories($skillsPath);
            $skillCount = count($dirs);
        }

        $items = [
            'routes' => $routeCount,
            'migrations' => $migrationCount,
            'config_files' => $configCount,
            'agent_skills' => $skillCount,
        ];

        if (! $this->option('json')) {
            $this->line("\n[Code Metrics]");
            $this->line("  Routes: {$routeCount}");
            $this->line("  Migrations: {$migrationCount}");
            $this->line("  Config files: {$configCount}");
            $this->line("  Agent skills: {$skillCount}");
        }

        $this->addSection('Code Metrics', $items);
    }

    private function addSection(string $name, array $items, array $extra = []): void
    {
        $this->sections[$name] = array_merge(['items' => $items], $extra);
    }
}
