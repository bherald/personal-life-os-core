<?php

namespace App\Console\Commands;

use App\Services\JoplinWriteService;
use Illuminate\Console\Command;

/**
 * Test Joplin Write - Phase 0 Testing
 *
 * Safely test Joplin write functionality with controlled test notes.
 * Part of YouTube Integration Phase 0: Joplin Sync Fix
 */
class JoplinTestWrite extends Command
{
    protected $signature = 'joplin:test-write
                            {--count=1 : Number of test notes to create}
                            {--notebook= : Parent notebook ID (optional)}
                            {--dry-run : Show what would be written without actually writing}';

    protected $description = 'Test Joplin write functionality (Phase 0 testing)';

    public function handle(JoplinWriteService $joplinService): int
    {
        $count = (int) $this->option('count');
        $notebookId = $this->option('notebook');
        $dryRun = $this->option('dry-run');

        $this->info('🧪 Joplin Write Test - Phase 0');
        $this->info('═══════════════════════════════════');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No actual writes will occur');
            $this->newLine();
        }

        $results = [];

        for ($i = 1; $i <= $count; $i++) {
            $testNumber = $i;
            $timestamp = now()->format('Y-m-d H:i:s');

            $title = "Phase 0 Test Note #{$testNumber}";
            $content = $this->buildTestContent($testNumber, $timestamp);

            $this->info("Test #{$testNumber}: Creating note...");
            $this->line("  Title: {$title}");
            $this->line('  Notebook: '.($notebookId ?? 'Root'));

            if ($dryRun) {
                // Show what would be written
                $this->newLine();
                $this->line('Would write:');
                $this->line($this->previewNote($title, $content, $notebookId));
                $this->newLine();

                $results[] = ['test' => $testNumber, 'status' => 'dry-run', 'note_id' => 'N/A'];

                continue;
            }

            // Actual write
            $result = $joplinService->createNote(
                $title,
                $content,
                $notebookId
            );

            if ($result['success']) {
                if (isset($result['queued']) && $result['queued']) {
                    $this->warn("  ⏳ Queued (Joplin busy) - Job ID: {$result['job_id']}");
                    $results[] = ['test' => $testNumber, 'status' => 'queued', 'job_id' => $result['job_id']];
                } else {
                    $this->info("  ✅ Created - Note ID: {$result['note_id']}");
                    $results[] = ['test' => $testNumber, 'status' => 'success', 'note_id' => $result['note_id']];
                }
            } else {
                $this->error("  ❌ Failed: {$result['error']}");
                $results[] = ['test' => $testNumber, 'status' => 'failed', 'error' => $result['error']];
            }

            // Brief pause between writes
            if ($i < $count) {
                sleep(2);
            }
        }

        // Summary
        $this->newLine();
        $this->info('═══════════════════════════════════');
        $this->info('Test Summary');
        $this->info('═══════════════════════════════════');

        $successful = collect($results)->where('status', 'success')->count();
        $queued = collect($results)->where('status', 'queued')->count();
        $failed = collect($results)->where('status', 'failed')->count();

        $this->table(
            ['Status', 'Count'],
            [
                ['✅ Successful', $successful],
                ['⏳ Queued', $queued],
                ['❌ Failed', $failed],
                ['📊 Total', $count],
            ]
        );

        if ($successful > 0 || $queued > 0) {
            $this->newLine();
            $this->info('✅ Next Steps:');
            $this->line('1. Open your Joplin client - verify test notes appear');
            $this->line("2. Check Joplin Sync Status - should be 'Completed' with no errors");
            $this->line('3. Open Joplin Mobile - trigger sync manually');
            $this->line('4. Verify test notes sync to mobile without corruption');
            $this->line('5. Report results back to continue Phase 0');
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error('Some writes failed. Check logs:');
            $this->line('tail -50 storage/logs/laravel.log | grep joplin -i');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Build test note content
     */
    private function buildTestContent(int $testNumber, string $timestamp): string
    {
        return <<<MARKDOWN
# Phase 0 Test Note #{$testNumber}

**Test Purpose:** Verify Joplin write functionality after format fix
**Created:** {$timestamp}
**Phase:** YouTube Integration - Phase 0

## Test Details

This is a controlled test note to verify that the Joplin write fix prevents sync corruption.

### Verification Checklist

- [ ] Note appears in a Joplin client
- [ ] Joplin sync completes without errors
- [ ] Note syncs to Joplin Mobile
- [ ] No corruption or duplicate notes
- [ ] Content is readable and formatted correctly

### Technical Info

- Test Number: {$testNumber}
- Framework: PLOS Laravel Automation
- Fix: buildNoteContent() rewritten to match Joplin spec
- Missing Fields Added: user_created_time, user_updated_time, encryption fields, sharing fields, etc.
- Bug Fixed: Removed invalid "---" separator

## Next Steps

If this note syncs successfully without corruption, the fix is working!

---

*Generated by: php artisan joplin:test-write*
MARKDOWN;
    }

    /**
     * Preview what a note would look like (for dry-run)
     */
    private function previewNote(string $title, string $content, ?string $notebookId): string
    {
        $noteId = bin2hex(random_bytes(16));
        $timestamp = now()->format('Y-m-d\TH:i:s.v\Z');

        return <<<NOTE
{$title}

{$content}

id: {$noteId}
created_time: {$timestamp}
updated_time: {$timestamp}
user_created_time: {$timestamp}
user_updated_time: {$timestamp}
encryption_cipher_text:
encryption_applied: 0
parent_id: {$notebookId}
is_shared: 0
share_id:
master_key_id:
icon:
user_data:
deleted_time: 0
type_: 1
NOTE;
    }
}
