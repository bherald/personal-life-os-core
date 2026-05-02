<?php

namespace App\Console\Commands;

use App\Services\EmailSuggestionService;
use Illuminate\Console\Command;

/**
 * EA2: Email Suggestions Command
 *
 * Scans emails for AI-powered suggestions (contacts, calendar events, bills),
 * sends notifications for urgent items, and generates daily bill digests.
 */
class EmailSuggestionsCommand extends Command
{
    protected $signature = 'email:suggestions
                            {--folder=Inbox : Email folder to scan}
                            {--limit=50 : Maximum emails to scan}
                            {--notify : Send Pushover notifications for urgent items}
                            {--digest : Generate and send daily bill digest}
                            {--cleanup : Remove expired/old suggestions}
                            {--all : Run all operations (scan, notify, digest, cleanup)}';

    protected $description = 'EA2: Scan emails for AI suggestions (contacts, calendar, bills)';

    public function __construct(
        private EmailSuggestionService $suggestionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $folder = $this->option('folder');
        $limit = (int) $this->option('limit');
        $doNotify = $this->option('notify');
        $doDigest = $this->option('digest');
        $doCleanup = $this->option('cleanup');
        $doAll = $this->option('all');

        // If --all, enable all operations
        if ($doAll) {
            $doNotify = true;
            $doDigest = true;
            $doCleanup = true;
        }

        $startTime = microtime(true);
        $this->info('EA2 Email Suggestions');
        $this->line('======================');
        $this->newLine();

        $hasErrors = false;

        // 1. Scan for new suggestions
        $this->info("Scanning {$folder} for suggestions (limit: {$limit})...");

        try {
            $scanResult = $this->suggestionService->scanAndProcess($folder, $limit);

            $this->line("  Scanned: {$scanResult['scanned']} emails");
            $classified = $scanResult['classified'] ?? 0;
            $this->line("  Classifications: {$classified}");
            $suggestionsCreated = $scanResult['suggestions_created'] ?? 0;
            $this->line("  New suggestions: {$suggestionsCreated}");

            // Show breakdown by type
            $contactsSuggested = $scanResult['contacts_suggested'] ?? 0;
            $calendarSuggested = $scanResult['calendar_suggested'] ?? 0;
            $billsDetected = $scanResult['bills_detected'] ?? 0;
            if ($contactsSuggested > 0 || $calendarSuggested > 0 || $billsDetected > 0) {
                if ($contactsSuggested > 0) {
                    $this->line("    - contacts: {$contactsSuggested}");
                }
                if ($calendarSuggested > 0) {
                    $this->line("    - calendar: {$calendarSuggested}");
                }
                if ($billsDetected > 0) {
                    $this->line("    - bills: {$billsDetected}");
                }
            }

            if (!empty($scanResult['errors'])) {
                $this->warn("  Errors: " . count($scanResult['errors']));
                foreach ($scanResult['errors'] as $error) {
                    if (is_array($error)) {
                        $errorMsg = $error['error'] ?? 'Unknown error';
                        $subject = $error['email_subject'] ?? '';
                        $this->warn("    - {$errorMsg}" . ($subject ? " (email: {$subject})" : ''));
                    } else {
                        $this->warn("    - {$error}");
                    }
                }
                $hasErrors = true;
            }
        } catch (\Exception $e) {
            $this->error("  Scan failed: {$e->getMessage()}");
            $hasErrors = true;
        }

        // 2. Send notifications for urgent items
        if ($doNotify) {
            $this->newLine();
            $this->info('Sending notifications...');

            try {
                $notifyResult = $this->suggestionService->sendEmailNotifications();

                if ($notifyResult['sent'] > 0) {
                    $this->info("  Sent {$notifyResult['sent']} notification(s)");
                } else {
                    $this->line('  No notifications to send');
                }

                if (!empty($notifyResult['errors'])) {
                    $this->warn("  Notification errors: " . count($notifyResult['errors']));
                    $hasErrors = true;
                }
            } catch (\Exception $e) {
                $this->error("  Notification failed: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        // 3. Generate bill digest
        if ($doDigest) {
            $this->newLine();
            $this->info('Generating bill digest...');

            try {
                $digestResult = $this->suggestionService->sendBillDigest();

                if ($digestResult['sent']) {
                    $billCount = $digestResult['bill_count'] ?? 0;
                    $this->info("  Bill digest sent: {$billCount} upcoming bills");
                } else {
                    $reason = $digestResult['reason'] ?? 'unknown reason';
                    $this->line("  No bills to report ({$reason})");
                }
            } catch (\Exception $e) {
                $this->error("  Digest failed: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        // 4. Cleanup operations
        if ($doCleanup) {
            $this->newLine();
            $this->info('Running cleanup operations...');

            try {
                $cleanupResult = $this->suggestionService->cleanup();
                $this->line("  Expired suggestions removed: {$cleanupResult['expired']}");
                $this->line("  Old classifications cleared: {$cleanupResult['old_classifications']}");
            } catch (\Exception $e) {
                $this->error("  Cleanup failed: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        // Summary
        $this->newLine();
        $stats = $this->suggestionService->getStats();
        $duration = round(microtime(true) - $startTime, 2);

        $this->info("Summary (completed in {$duration}s):");
        $this->table(
            ['Type', 'Pending'],
            [
                ['Contacts', $stats['contact'] ?? 0],
                ['Calendar', $stats['calendar'] ?? 0],
                ['Bills', $stats['bill'] ?? 0],
                ['Total', $stats['total'] ?? 0],
            ]
        );

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
