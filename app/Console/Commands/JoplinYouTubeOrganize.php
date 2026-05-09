<?php

namespace App\Console\Commands;

use App\Services\JoplinYouTubeOrganizer;
use Illuminate\Console\Command;

class JoplinYouTubeOrganize extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'joplin:youtube-organize
                            {--dry-run : Show what would be done without making changes}
                            {--list-categories : List available categories and their keywords}
                            {--consolidate : Process ALL Watch Later folders, not just primary}
                            {--ai-categorize : Use AI for notes that don\'t match keyword categories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Organize YouTube Watch Later folder: remove duplicates and categorize videos into subfolders';

    /**
     * Execute the console command.
     */
    public function handle(JoplinYouTubeOrganizer $organizer): int
    {
        // List categories only
        if ($this->option('list-categories')) {
            $this->info('Available Categories:');
            $this->newLine();

            foreach ($organizer->getCategories() as $category => $keywords) {
                $this->line("<comment>{$category}</comment>");
                $this->line('  Keywords: '.implode(', ', array_slice($keywords, 0, 10)).(count($keywords) > 10 ? '...' : ''));
                $this->newLine();
            }

            return self::SUCCESS;
        }

        // Run organization
        $dryRun = $this->option('dry-run');
        $consolidate = $this->option('consolidate');
        $useAI = $this->option('ai-categorize');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $mode = $consolidate ? 'consolidation (all folders)' : 'organization (primary only)';
        $this->info("Starting YouTube Watch Later {$mode}...");
        if ($useAI) {
            $this->info('AI categorization enabled for unmatched notes');
        }
        $this->newLine();

        try {
            $organizer->setOutput($this->output);

            if ($consolidate) {
                $stats = $organizer->organizeAll($dryRun, $useAI);
            } else {
                $stats = $organizer->organize($dryRun);
            }

            if (! empty($stats['skipped'])) {
                $reason = (string) ($stats['skipped_reason'] ?? 'Joplin YouTube organizer prerequisites are not configured');

                $this->newLine();
                $this->warn('Organization skipped: '.$reason);
                $this->line('[ITEMS_PROCESSED:0]');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->info('Organization complete!');
            $this->newLine();

            $rows = [
                ['Total Notes', $stats['total_notes']],
                ['Duplicates Found', $stats['duplicates_found']],
                ['Duplicates Deleted', $stats['duplicates_deleted']],
                ['Categories Created', $stats['categories_created']],
                ['Notes Moved', $stats['notes_moved']],
                ['Failed Operations', $stats['failed_operations']],
                ['Dry Run', $stats['dry_run'] ? 'Yes' : 'No'],
            ];

            if ($consolidate) {
                array_splice($rows, 1, 0, [
                    ['Extra Folders Found', $stats['extra_folders_found']],
                    ['Extra Folders Deleted', $stats['extra_folders_deleted']],
                    ['AI Categorized', $stats['ai_categorized']],
                ]);

                if (! empty($stats['per_source'])) {
                    $this->table(
                        ['Source', 'Notes'],
                        array_map(fn ($source, $count) => [$source, $count], array_keys($stats['per_source']), array_values($stats['per_source']))
                    );
                    $this->newLine();
                }
            }

            $this->table(['Metric', 'Value'], $rows);

            $successfulOperations = (int) ($stats['duplicates_deleted'] ?? 0)
                + (int) ($stats['notes_moved'] ?? 0)
                + (int) ($stats['categories_created'] ?? 0)
                + (int) ($stats['extra_folders_deleted'] ?? 0)
                + (int) ($stats['ai_categorized'] ?? 0);

            $this->line('[ITEMS_PROCESSED:'.max(0, $successfulOperations).']');

            if (($stats['failed_operations'] ?? 0) > 0) {
                $this->warn("Completed with {$stats['failed_operations']} failed operation(s).");
            }

            // Maintenance runs should only fail hard when no useful work completed.
            return (($stats['failed_operations'] ?? 0) > 0 && $successfulOperations === 0)
                ? self::FAILURE
                : self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
