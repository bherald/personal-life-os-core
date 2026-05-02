<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Genealogy\GenealogyBackfillService;

class GenealogyBackfillSources extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genealogy:backfill-sources
                            {--tree= : Tree ID to process (required)}
                            {--dry-run : Show what would be done without making changes}
                            {--media-sources : Link media to sources based on title/filename matching}
                            {--person-sources : Link sources to persons by name matching}
                            {--citations : Create citations from GEDCOM source references}
                            {--all : Run all backfill operations}
                            {--stats : Show statistics only, no backfill}';

    /**
     * The console command description.
     */
    protected $description = 'AI-powered backfill of genealogy source and media relationships';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $treeId = $this->option('tree');
        $dryRun = $this->option('dry-run');
        $runAll = $this->option('all');
        $statsOnly = $this->option('stats');

        if (!$treeId) {
            $this->error('Tree ID is required. Use --tree=<id>');
            return Command::FAILURE;
        }

        $this->info('Genealogy Source/Media Backfill');
        $this->info('================================');
        $this->info("Tree ID: {$treeId}");

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        $service = new GenealogyBackfillService();

        // Show current statistics
        $this->newLine();
        $this->info('Current Statistics:');
        $stats = $service->getBackfillStats((int)$treeId);
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sources', $stats['sources_total']],
                ['Total Media', $stats['media_total']],
                ['Total Citations', $stats['citations_total']],
                ['Citations with Media', $stats['citations_with_media']],
                ['Person-Source Links', $stats['person_source_links']],
                ['Persons with Sources', $stats['persons_with_sources']],
                ['Total Persons', $stats['persons_total']],
            ]
        );

        if ($statsOnly) {
            return Command::SUCCESS;
        }

        // Determine which operations to run
        $runMediaSources = $runAll || $this->option('media-sources');
        $runPersonSources = $runAll || $this->option('person-sources');
        $runCitations = $runAll || $this->option('citations');

        if (!$runMediaSources && !$runPersonSources && !$runCitations) {
            $this->warn('No operations specified. Use --all or specific options:');
            $this->line('  --media-sources  : Link media to sources by title matching');
            $this->line('  --person-sources : Link sources to persons by name matching');
            $this->line('  --citations      : Create citations from GEDCOM');
            $this->line('  --all            : Run all operations');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('Running backfill operations...');

        // Media to Sources linking
        if ($runMediaSources) {
            $this->newLine();
            $this->info('1. Linking Media to Sources...');
            $result = $service->linkMediaToSources((int)$treeId, $dryRun);

            $this->line("   Matched: {$result['matched']}");
            $this->line("   Skipped (already linked): {$result['skipped']}");

            if ($result['matched'] > 0 && $this->output->isVerbose()) {
                $this->table(
                    ['Media ID', 'Media Title', 'Source ID', 'Source Title'],
                    array_map(fn($d) => [
                        $d['media_id'],
                        substr($d['media_title'] ?? '', 0, 40),
                        $d['source_id'],
                        substr($d['source_title'] ?? '', 0, 40),
                    ], array_slice($result['details'], 0, 20))
                );
                if (count($result['details']) > 20) {
                    $this->line("   ... and " . (count($result['details']) - 20) . " more");
                }
            }
        }

        // Sources to Persons linking
        if ($runPersonSources) {
            $this->newLine();
            $this->info('2. Linking Sources to Persons by Title...');
            $result = $service->linkSourcesToPersonsByTitle((int)$treeId, $dryRun);

            $this->line("   Linked: {$result['linked']}");
            $this->line("   Skipped (already linked): {$result['skipped']}");

            if ($result['linked'] > 0 && $this->output->isVerbose()) {
                $this->table(
                    ['Person ID', 'Person Name', 'Source ID', 'Source Title'],
                    array_map(fn($d) => [
                        $d['person_id'],
                        substr($d['person_name'] ?? '', 0, 30),
                        $d['source_id'],
                        substr($d['source_title'] ?? '', 0, 40),
                    ], array_slice($result['details'], 0, 20))
                );
                if (count($result['details']) > 20) {
                    $this->line("   ... and " . (count($result['details']) - 20) . " more");
                }
            }
        }

        // Citations from GEDCOM
        if ($runCitations) {
            $this->newLine();
            $this->info('3. Creating Citations from GEDCOM...');
            $result = $service->createCitationsFromGedcom((int)$treeId, $dryRun);

            $this->line("   Created: {$result['created']}");
            $this->line("   Skipped (already exist): {$result['skipped']}");
            $this->line("   Errors: {$result['errors']}");

            if (!empty($result['details'])) {
                foreach ($result['details'] as $detail) {
                    if (is_string($detail)) {
                        $this->warn("   {$detail}");
                    }
                }
            }
        }

        // Show updated statistics
        $this->newLine();
        $this->info('Updated Statistics:');
        $stats = $service->getBackfillStats((int)$treeId);
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sources', $stats['sources_total']],
                ['Total Media', $stats['media_total']],
                ['Total Citations', $stats['citations_total']],
                ['Citations with Media', $stats['citations_with_media']],
                ['Person-Source Links', $stats['person_source_links']],
                ['Persons with Sources', $stats['persons_with_sources']],
                ['Total Persons', $stats['persons_total']],
            ]
        );

        $this->newLine();
        if ($dryRun) {
            $this->info('DRY RUN complete. Run without --dry-run to apply changes.');
        } else {
            $this->info('Backfill complete!');
        }

        return Command::SUCCESS;
    }
}
