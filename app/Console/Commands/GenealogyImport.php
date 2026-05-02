<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyService;
use App\Services\Genealogy\GenealogyMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Genealogy Import Command
 *
 * Imports GEDCOM files and associated media into the genealogy system.
 *
 * E20: Family Tree App
 */
class GenealogyImport extends Command
{
    protected $signature = 'genealogy:import
                            {file : Path to GEDCOM file (.ged)}
                            {--tree= : Import into existing tree ID}
                            {--name= : Name for new tree (defaults to filename)}
                            {--media : Also import media files from Windows}
                            {--media-path= : Base path on Windows for media files}
                            {--dry-run : Parse and show stats without importing}';

    protected $description = 'Import a GEDCOM file into the genealogy system';

    private GenealogyService $genealogyService;
    private GenealogyMediaService $mediaService;

    public function __construct(
        GenealogyService $genealogyService,
        GenealogyMediaService $mediaService
    ) {
        parent::__construct();
        $this->genealogyService = $genealogyService;
        $this->mediaService = $mediaService;
    }

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $treeId = $this->option('tree') ? (int) $this->option('tree') : null;
        $treeName = $this->option('name');
        $importMedia = $this->option('media');
        $mediaPath = $this->option('media-path');
        $dryRun = $this->option('dry-run');

        // Validate file
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("Processing GEDCOM file: {$filePath}");
        $this->newLine();

        // Parse for preview
        $this->info('Parsing GEDCOM file...');
        $parser = new \App\Services\Genealogy\GedcomParserService($filePath);
        $data = $parser->parse();
        $stats = $parser->getStatistics();

        // Show stats
        $this->table(
            ['Record Type', 'Count'],
            [
                ['Persons (INDI)', $stats['persons']],
                ['Families (FAM)', $stats['families']],
                ['Media (OBJE)', $stats['media']],
                ['Sources (SOUR)', $stats['sources']],
            ]
        );

        if ($dryRun) {
            $this->info('Dry run - no changes made.');

            // Show sample persons
            $this->newLine();
            $this->info('Sample persons:');
            $samples = array_slice($data['persons'], 0, 5);
            foreach ($samples as $person) {
                $name = ($person['given_name'] ?? '') . ' ' . ($person['surname'] ?? '');
                $dates = '';
                if (!empty($person['birth_date'])) $dates .= 'b.' . $person['birth_date'];
                if (!empty($person['death_date'])) $dates .= ' d.' . $person['death_date'];
                $this->line("  - {$name} {$dates}");
            }

            return Command::SUCCESS;
        }

        // Confirm import
        if (!$this->confirm('Proceed with import?', true)) {
            $this->info('Import cancelled.');
            return Command::SUCCESS;
        }

        // Perform import
        $this->newLine();
        $this->info('Importing data...');

        $result = $this->genealogyService->importGedcom($filePath, $treeId, $treeName);

        if (!$result['success']) {
            $this->error('Import failed!');
            foreach ($result['errors'] as $error) {
                $this->error("  - {$error}");
            }
            return Command::FAILURE;
        }

        // Show results
        $this->newLine();
        $this->info('Import completed successfully!');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Tree ID', $result['tree_id']],
                ['Persons Imported', $result['persons_imported']],
                ['Families Imported', $result['families_imported']],
                ['Media Records', $result['media_imported']],
                ['Sources Imported', $result['sources_imported']],
                ['Duration', $result['duration_seconds'] . ' seconds'],
                ['Errors', count($result['errors'])],
            ]
        );

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->warn('Import completed with errors:');
            foreach (array_slice($result['errors'], 0, 10) as $error) {
                $this->warn("  - " . (is_array($error) ? json_encode($error) : $error));
            }
            if (count($result['errors']) > 10) {
                $this->warn("  ... and " . (count($result['errors']) - 10) . " more errors");
            }
        }

        // Import media if requested
        if ($importMedia) {
            $this->newLine();
            $this->info('Importing media files from Windows...');

            $tree = $this->genealogyService->getTree($result['tree_id']);
            $mediaResult = $this->mediaService->importTreeMedia(
                $result['tree_id'],
                $tree->name,
                $mediaPath
            );

            $this->table(
                ['Media Import', 'Value'],
                [
                    ['Total Files', $mediaResult['total']],
                    ['Imported', $mediaResult['imported']],
                    ['Skipped', $mediaResult['skipped']],
                    ['Failed', $mediaResult['failed']],
                ]
            );

            if (!empty($mediaResult['errors'])) {
                $this->warn('Media import errors:');
                foreach (array_slice($mediaResult['errors'], 0, 5) as $error) {
                    $this->warn("  - " . (is_array($error) ? json_encode($error) : $error));
                }
            }
        }

        Log::info('GEDCOM import completed via CLI', [
            'file' => $filePath,
            'tree_id' => $result['tree_id'],
            'persons' => $result['persons_imported'],
        ]);

        return Command::SUCCESS;
    }
}
