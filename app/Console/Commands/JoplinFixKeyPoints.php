<?php

namespace App\Console\Commands;

use App\Nodes\YouTube\YouTubeKeyPointsPostProcessor;
use App\Services\AIService;
use App\Services\JoplinYouTubeOrganizer;
use Illuminate\Console\Command;

class JoplinFixKeyPoints extends Command
{
    protected $signature = 'joplin:fix-key-points
                            {--dry-run : Show what would be done without making changes}
                            {--limit=50 : Maximum notes to process per run}';

    protected $description = 'Backfill missing key points in YouTube Watch Later notes that have placeholder text';

    public function handle(JoplinYouTubeOrganizer $organizer, AIService $aiService): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info("Scanning Watch Later notes for missing key points (limit: {$limit})...");
        $this->newLine();

        try {
            $processor = new YouTubeKeyPointsPostProcessor();
            $stats = $processor->processPlaceholderNotes($organizer, $aiService, $limit, $dryRun, $this->output);

            $this->newLine();
            $this->info('Key points backfill complete!');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Notes Scanned', $stats['scanned']],
                    ['Placeholders Found', $stats['found']],
                    ['Notes Updated', $dryRun ? "{$stats['updated']} (dry run)" : $stats['updated']],
                    ['Failed', $stats['failed']],
                ]
            );

            return $stats['failed'] > 0 && $stats['updated'] === 0 ? self::FAILURE : self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
