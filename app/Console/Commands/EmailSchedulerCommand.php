<?php

namespace App\Console\Commands;

use App\Services\EmailEnhancementsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Email Scheduler Command
 *
 * Processes scheduled emails, lists pending, and shows stats.
 *
 * Usage:
 *   php artisan email:scheduled --process
 *   php artisan email:scheduled --list
 *   php artisan email:scheduled --stats
 */
class EmailSchedulerCommand extends Command
{
    protected $signature = 'email:scheduled
        {--process : Process due scheduled emails}
        {--list : List pending scheduled emails}
        {--stats : Show scheduling statistics}';

    protected $description = 'Manage scheduled email sending';

    public function handle(): int
    {
        $service = app(EmailEnhancementsService::class);

        if ($this->option('process')) {
            return $this->processScheduled($service);
        }

        if ($this->option('list')) {
            return $this->listScheduled($service);
        }

        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        $this->info('Usage: email:scheduled --process|--list|--stats');
        return self::SUCCESS;
    }

    private function processScheduled(EmailEnhancementsService $service): int
    {
        $this->info('Processing scheduled emails...');
        $results = $service->processScheduledEmails();

        $this->info("Processed: {$results['processed']}, Sent: {$results['sent']}, Failed: {$results['failed']}");

        foreach ($results['errors'] as $error) {
            $this->error("  {$error}");
        }

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function listScheduled(EmailEnhancementsService $service): int
    {
        $scheduled = $service->getScheduledEmails();

        if (empty($scheduled)) {
            $this->info('No pending scheduled emails.');
            return self::SUCCESS;
        }

        $rows = array_map(fn($s) => [
            $s->id,
            $s->draft_id,
            substr($s->to ?? '', 0, 30),
            substr($s->subject ?? '', 0, 40),
            $s->next_send_at,
            $s->recurring_pattern ?? 'once',
        ], $scheduled);

        $this->table(['ID', 'Draft', 'To', 'Subject', 'Next Send', 'Recurring'], $rows);
        return self::SUCCESS;
    }

    private function showStats(EmailEnhancementsService $service): int
    {
        $stats = $service->getOverviewStats();

        $this->info('Email Overview:');
        $this->line("  Drafts: {$stats['drafts']->total} total, {$stats['drafts']->pending} pending, {$stats['drafts']->sent} sent");
        $this->line("  Scheduled: {$stats['scheduled_pending']} pending");
        $this->line("  Attachments: {$stats['attachments']['count']} files");
        $this->line("  AI Accuracy: {$stats['ai_accuracy']['approval_rate']}% approval rate ({$stats['ai_accuracy']['total_ai_drafts']} AI drafts)");

        return self::SUCCESS;
    }
}
