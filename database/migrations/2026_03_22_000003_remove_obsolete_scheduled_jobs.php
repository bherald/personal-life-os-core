<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove 8 obsolete scheduled jobs:
 * - agent_expire_reviews: redundant, agent_cleanup_all (--all) covers --reviews
 * - ai_model_discovery: redundant, ai_ops_agent runs every 15min with same command
 * - 6 privacy jobs: covered by data-removal-ops agent (now enabled)
 */
return new class extends Migration
{
    private array $obsoleteJobs = [
        'agent_expire_reviews',
        'ai_model_discovery',
        'data_removal_scan',
        'data_removal_digest',
        'data_removal_discover',
        'data_removal_badbool_sync',
        'data_removal_breach_scan',
        'data_removal_relisting_scan',
    ];

    public function up(): void
    {
        $placeholders = implode(',', array_fill(0, count($this->obsoleteJobs), '?'));
        DB::delete(
            "DELETE FROM scheduled_jobs WHERE name IN ({$placeholders})",
            $this->obsoleteJobs
        );
    }

    public function down(): void
    {
        $restore = [
            ['agent_expire_reviews', 'php artisan agent:cleanup --reviews', '0 * * * *', 1, 'Maintenance', 'Runs hourly to mark expired review items (48hr default TTL)'],
            ['ai_model_discovery', 'ai-ops', '0 7 * * 0', 1, 'Maintenance', '{"task":"Run check_model_updates to discover new and deprecated models across all LLM providers.","notify":false}'],
            ['data_removal_scan', 'data-removal:scan --all --limit=200', '0 3 * * 0', 0, 'Privacy', 'Scans up to 50 brokers per subject, creates removal requests for found data.'],
            ['data_removal_digest', 'data-removal:digest', '0 8 * * *', 0, 'Privacy', 'Morning report of pending data removal requests.'],
            ['data_removal_discover', 'data-removal:discover --seed', '0 2 1 * *', 0, 'Privacy', 'Finds and adds new broker sites.'],
            ['data_removal_badbool_sync', 'data-removal:tools --sync-badbool', '0 2 * * 0', 0, 'Privacy', 'Syncs 800+ data brokers from Big Ass Data Broker Opt Out List'],
            ['data_removal_breach_scan', 'data-removal:tools --breach-scan', '0 4 * * 0', 0, 'Privacy', 'Checks Have I Been Pwned for new breaches affecting data subjects'],
            ['data_removal_relisting_scan', 'data-removal:tools --relisting-scan', '0 5 1 * *', 0, 'Privacy', 'Monthly check for relistings - triggers auto-resubmit if found'],
        ];

        foreach ($restore as [$name, $command, $cron, $enabled, $category, $notes]) {
            DB::insert(
                "INSERT IGNORE INTO scheduled_jobs (name, job_type, command, cron_expression, enabled, timeout_minutes, category, notes, created_at, updated_at)
                 VALUES (?, 'command', ?, ?, ?, 30, ?, ?, NOW(), NOW())",
                [$name, $command, $cron, $enabled, $category, $notes]
            );
        }
    }
};
