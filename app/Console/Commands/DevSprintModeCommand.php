<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Dev Sprint Mode — reduce framework load during development sessions.
 *
 * Disables non-essential agents and reduces frequencies to minimize
 * Claude CLI token consumption and GPU contention. Saves original
 * state for restore.
 *
 * Usage:
 *   php artisan dev:sprint --activate     Enable sprint mode (reduce load)
 *   php artisan dev:sprint --restore      Restore original schedules
 *   php artisan dev:sprint --status       Show current sprint state
 *
 * Sprint mode reduces agent-driven Claude calls by ~70% while keeping
 * stability monitoring (ai-ops, system-guardian, log-analyst, reports).
 */
class DevSprintModeCommand extends Command
{
    protected $signature = 'dev:sprint
                            {--activate : Activate sprint mode (reduce load)}
                            {--restore : Restore original schedules}
                            {--status : Show current sprint state}';

    protected $description = 'Toggle dev sprint mode — reduce agent load during development';

    private const CACHE_KEY = 'dev_sprint_original_state';

    /**
     * Jobs to DISABLE during sprint (not needed for stability vetting)
     */
    private const DISABLE_JOBS = [
        'knowledge_curator_agent',
        'factcheck_ops_agent',
        'research_analyst_agent',
        'file_curator_agent',
    ];

    /**
     * Jobs to REDUCE frequency during sprint
     * [name => new_cron_expression]
     */
    private const REDUCE_JOBS = [
        'research_ops_agent'  => '0 */4 * * *',   // 30min → 4h
        'workflow_ops_agent'  => '0 */4 * * *',   // 30min → 4h
        'file_ops_agent'      => '0 */2 * * *',   // 30min → 2h
        'youtube_ops_agent'   => '0 */4 * * *',   // 30min → 4h
    ];

    /**
     * Jobs to KEEP as-is (stability monitoring)
     */
    private const KEEP_JOBS = [
        'ai_ops_agent',            // 15min — validates AI changes
        'system_guardian_agent',    // 30min — system health
        'log_analyst_agent',       // 2h — catches deploy errors
        'daily_report',            // 5:50 AM
        'midday_digest',           // 4 PM
        'genealogy_agent_research', // 4 AM daily
    ];

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('activate')) {
            return $this->activate();
        }

        if ($this->option('restore')) {
            return $this->restore();
        }

        $this->showStatus();
        return 0;
    }

    private function activate(): int
    {
        if (Cache::has(self::CACHE_KEY)) {
            $this->warn('Sprint mode already active. Use --restore first to deactivate.');
            return 1;
        }

        // Save original state
        $originalState = [];

        // Save and disable
        foreach (self::DISABLE_JOBS as $name) {
            $job = DB::selectOne("SELECT id, enabled, cron_expression FROM scheduled_jobs WHERE name = ?", [$name]);
            if ($job) {
                $originalState[$name] = ['enabled' => $job->enabled, 'cron' => $job->cron_expression];
                DB::update("UPDATE scheduled_jobs SET enabled = 0, updated_at = NOW() WHERE name = ?", [$name]);
                $this->line("  DISABLED  {$name}");
            }
        }

        // Save and reduce frequency
        foreach (self::REDUCE_JOBS as $name => $newCron) {
            $job = DB::selectOne("SELECT id, enabled, cron_expression FROM scheduled_jobs WHERE name = ?", [$name]);
            if ($job) {
                $originalState[$name] = ['enabled' => $job->enabled, 'cron' => $job->cron_expression];
                DB::update("UPDATE scheduled_jobs SET cron_expression = ?, updated_at = NOW() WHERE name = ?", [$newCron, $name]);
                $this->line("  REDUCED   {$name}: {$job->cron_expression} → {$newCron}");
            }
        }

        // Store original state in cache (30 days — long enough for any sprint)
        Cache::put(self::CACHE_KEY, $originalState, 2592000);
        Cache::put('dev_sprint_activated_at', now()->toIso8601String(), 2592000);

        $disabled = count(self::DISABLE_JOBS);
        $reduced = count(self::REDUCE_JOBS);
        $kept = count(self::KEEP_JOBS);

        $this->info("Sprint mode ACTIVATED: {$disabled} disabled, {$reduced} reduced, {$kept} unchanged.");
        $this->info('Estimated ~70% reduction in agent-driven Claude CLI calls.');
        $this->line('Run: php artisan dev:sprint --restore  when done.');

        Log::info('Dev sprint mode activated', [
            'disabled' => self::DISABLE_JOBS,
            'reduced' => array_keys(self::REDUCE_JOBS),
        ]);

        return 0;
    }

    private function restore(): int
    {
        $originalState = Cache::get(self::CACHE_KEY);

        if (!$originalState) {
            $this->warn('No sprint state saved. Sprint mode may not be active.');
            return 1;
        }

        foreach ($originalState as $name => $state) {
            DB::update(
                "UPDATE scheduled_jobs SET enabled = ?, cron_expression = ?, updated_at = NOW() WHERE name = ?",
                [$state['enabled'], $state['cron'], $name]
            );
            $this->line("  RESTORED  {$name}: enabled={$state['enabled']}, cron={$state['cron']}");
        }

        $activatedAt = Cache::get('dev_sprint_activated_at', 'unknown');
        Cache::forget(self::CACHE_KEY);
        Cache::forget('dev_sprint_activated_at');

        $this->info('Sprint mode DEACTIVATED. All ' . count($originalState) . ' jobs restored to original state.');
        $this->line("Sprint was active since: {$activatedAt}");

        Log::info('Dev sprint mode deactivated', ['restored' => count($originalState)]);

        return 0;
    }

    private function showStatus(): int
    {
        $isActive = Cache::has(self::CACHE_KEY);
        $activatedAt = Cache::get('dev_sprint_activated_at');

        if ($isActive) {
            $this->info("Sprint mode: ACTIVE (since {$activatedAt})");
        } else {
            $this->info('Sprint mode: INACTIVE');
        }

        $this->line('');
        $this->line('KEEP (stability monitoring):');
        foreach (self::KEEP_JOBS as $name) {
            $job = DB::selectOne("SELECT cron_expression, enabled FROM scheduled_jobs WHERE name = ?", [$name]);
            $status = $job ? ($job->enabled ? "ON  {$job->cron_expression}" : 'OFF') : 'NOT FOUND';
            $this->line("  {$name}: {$status}");
        }

        $this->line('');
        $this->line('DISABLE during sprint:');
        foreach (self::DISABLE_JOBS as $name) {
            $job = DB::selectOne("SELECT cron_expression, enabled FROM scheduled_jobs WHERE name = ?", [$name]);
            $status = $job ? ($job->enabled ? "ON  {$job->cron_expression}" : 'OFF') : 'NOT FOUND';
            $this->line("  {$name}: {$status}");
        }

        $this->line('');
        $this->line('REDUCE during sprint:');
        foreach (self::REDUCE_JOBS as $name => $sprintCron) {
            $job = DB::selectOne("SELECT cron_expression, enabled FROM scheduled_jobs WHERE name = ?", [$name]);
            $status = $job ? "ON  {$job->cron_expression}" : 'NOT FOUND';
            $this->line("  {$name}: {$status} (sprint: {$sprintCron})");
        }

        return 0;
    }
}
