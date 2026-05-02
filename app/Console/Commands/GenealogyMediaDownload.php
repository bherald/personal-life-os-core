<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMediaDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command to analyze and download missing genealogy media from external sources
 *
 * E20: Family Tree App - Media Recovery
 */
class GenealogyMediaDownload extends Command
{
    protected $signature = 'genealogy:media-download
                            {tree_id : Tree ID to process}
                            {--analyze : Only analyze what can be downloaded (no downloads)}
                            {--source=all : Source type to download (findagrave, familysearch, newspapers, loc, all)}
                            {--limit=10 : Maximum number of items to download}
                            {--dry-run : Show what would be downloaded without actually downloading}';

    protected $description = 'Analyze and download missing genealogy media from external sources';

    protected GenealogyMediaDownloadService $downloadService;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $treeId = (int) $this->argument('tree_id');
        $analyze = $this->option('analyze');
        $sourceType = $this->option('source');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->downloadService = new GenealogyMediaDownloadService();

        // Verify tree exists
        $tree = DB::selectOne("SELECT * FROM genealogy_trees WHERE id = ?", [$treeId]);
        if (!$tree) {
            $this->error("Tree ID {$treeId} not found");
            return Command::FAILURE;
        }

        $this->info("Processing tree: {$tree->name}");
        $this->newLine();

        // Always run analysis first
        $this->info('Analyzing downloadable sources...');
        $analysis = $this->downloadService->analyzeDownloadableSources($treeId);

        if (isset($analysis['error'])) {
            $this->error($analysis['error']);
            return Command::FAILURE;
        }

        // Display analysis
        $this->table(
            ['Source Type', 'Unique Sources', 'Downloadable Items'],
            [
                ['FamilySearch', $analysis['by_source_type']['familysearch'], count($analysis['downloadable']['familysearch_arks'])],
                ['FindAGrave', $analysis['by_source_type']['findagrave'], count($analysis['downloadable']['findagrave_ids'])],
                ['Newspapers.com', $analysis['by_source_type']['newspapers'], count($analysis['downloadable']['newspapers_urls'])],
                ['Ancestry', $analysis['by_source_type']['ancestry'], 'Requires auth'],
                ['Other URLs', $analysis['by_source_type']['other_urls'], count($analysis['downloadable']['other_urls'])],
            ]
        );

        $this->newLine();
        $this->info("Total citations: {$analysis['total_citations']}");
        $this->info("Citations with URLs: {$analysis['citations_with_urls']}");

        // Display notes about each source
        $this->newLine();
        $this->comment('Notes:');
        foreach ($analysis['notes'] as $source => $note) {
            $this->line("  [{$source}] {$note}");
        }
        $this->line("  [loc] LOC Chronicling America: FREE historical newspapers 1736-1963");
        $this->line("        Use: php artisan genealogy:loc-search {$treeId} --query=\"Name\" --state=Pennsylvania");

        if ($analyze) {
            $this->newLine();
            $this->info('Analysis complete. Use without --analyze to download.');

            // Show sample URLs for each type
            $this->showSampleUrls($analysis);

            return Command::SUCCESS;
        }

        // Proceed with downloads
        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN - No files will be downloaded');
        }

        $this->newLine();
        $this->info("Starting downloads (limit: {$limit})...");

        $downloaded = 0;
        $failed = 0;

        // Download FindAGrave memorials
        if ($sourceType === 'all' || $sourceType === 'findagrave') {
            $findagraveIds = array_slice($analysis['downloadable']['findagrave_ids'], 0, $limit);

            if (!empty($findagraveIds)) {
                $this->newLine();
                $this->info('Downloading FindAGrave memorials...');

                foreach ($findagraveIds as $item) {
                    $memorialId = $item['url'];
                    $this->line("  Memorial ID: {$memorialId}");

                    if (!$dryRun) {
                        $result = $this->downloadService->downloadFindAGraveMedia($memorialId, $treeId);

                        if ($result['success']) {
                            $downloaded += count($result['files']);
                            $this->info("    Downloaded " . count($result['files']) . " file(s)");

                            // Create media records and link to source
                            foreach ($result['files'] as $file) {
                                $mediaId = $this->downloadService->createMediaRecord($treeId, $file['filepath'], [
                                    'title' => "FindAGrave Memorial {$memorialId}",
                                    'source' => 'FindAGrave',
                                    'source_url' => "https://www.findagrave.com/memorial/{$memorialId}",
                                ]);

                                // Link to source if we can find it
                                $this->linkToSource($treeId, $mediaId, $item);
                            }
                        } else {
                            $failed++;
                            foreach ($result['errors'] as $error) {
                                $this->warn("    Error: {$error}");
                            }
                        }

                        // Rate limiting
                        sleep(2);
                    }
                }
            }
        }

        // Download from Newspapers.com
        if ($sourceType === 'all' || $sourceType === 'newspapers') {
            $newspapersUrls = array_slice($analysis['downloadable']['newspapers_urls'], 0, $limit);

            if (!empty($newspapersUrls)) {
                $this->newLine();
                $this->info('Downloading from Newspapers.com...');

                foreach ($newspapersUrls as $item) {
                    $url = $item['url'];
                    $this->line("  URL: " . substr($url, 0, 80) . "...");

                    if (!$dryRun) {
                        $result = $this->downloadService->downloadNewspapersMedia($url, $treeId);

                        if ($result['success']) {
                            $downloaded++;
                            $this->info("    Downloaded: {$result['filename']}");

                            $mediaId = $this->downloadService->createMediaRecord($treeId, $result['filepath'], [
                                'title' => $result['title'] ?? 'Newspapers.com Clipping',
                                'source' => 'Newspapers.com',
                                'source_url' => $url,
                            ]);

                            $this->linkToSource($treeId, $mediaId, $item);
                        } else {
                            $failed++;
                            $this->warn("    Error: {$result['error']}");
                        }

                        // Rate limiting
                        sleep(2);
                    }
                }
            }
        }

        // Download from direct URLs (PDFs, images, etc.)
        if ($sourceType === 'all' || $sourceType === 'other') {
            $otherUrls = array_slice($analysis['downloadable']['other_urls'], 0, $limit);

            // Filter to only safe, downloadable file types
            $otherUrls = array_filter($otherUrls, function ($item) {
                $url = $item['url'];
                // Only download PDFs and images from trusted domains
                $trustedDomains = ['archives.lib.state.ma.us', 'newspapers.com'];
                $trustedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];

                $host = parse_url($url, PHP_URL_HOST);
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

                return in_array($host, $trustedDomains) || in_array($ext, $trustedExtensions);
            });

            if (!empty($otherUrls)) {
                $this->newLine();
                $this->info('Downloading from other URLs...');

                foreach (array_slice($otherUrls, 0, $limit - $downloaded) as $item) {
                    $url = $item['url'];
                    $this->line("  URL: " . substr($url, 0, 80) . "...");

                    if (!$dryRun) {
                        $result = $this->downloadService->downloadFile($url, $treeId, 'url');

                        if ($result['success']) {
                            $downloaded++;
                            $this->info("    Downloaded: {$result['filename']}");

                            $mediaId = $this->downloadService->createMediaRecord($treeId, $result['filepath'], [
                                'title' => basename(parse_url($url, PHP_URL_PATH)),
                                'source' => parse_url($url, PHP_URL_HOST),
                                'source_url' => $url,
                            ]);

                            $this->linkToSource($treeId, $mediaId, $item);
                        } else {
                            $failed++;
                            $this->warn("    Error: {$result['error']}");
                        }

                        sleep(1);
                    }
                }
            }
        }

        // Summary
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Downloaded', $downloaded],
                ['Failed', $failed],
            ]
        );

        if ($downloaded > 0) {
            $this->info("Files saved to: " . storage_path('app/public/genealogy/downloaded'));
        }

        Log::info('Genealogy media download completed', [
            'tree_id' => $treeId,
            'downloaded' => $downloaded,
            'failed' => $failed,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Show sample URLs for each source type
     */
    protected function showSampleUrls(array $analysis): void
    {
        $this->newLine();
        $this->info('Sample downloadable URLs:');

        if (!empty($analysis['downloadable']['familysearch_arks'])) {
            $this->newLine();
            $this->comment('FamilySearch ARK URLs:');
            foreach (array_slice($analysis['downloadable']['familysearch_arks'], 0, 3) as $item) {
                $this->line("  {$item['url']}");
            }
        }

        if (!empty($analysis['downloadable']['findagrave_ids'])) {
            $this->newLine();
            $this->comment('FindAGrave Memorial IDs:');
            foreach (array_slice($analysis['downloadable']['findagrave_ids'], 0, 3) as $item) {
                $this->line("  Memorial ID: {$item['url']} -> https://www.findagrave.com/memorial/{$item['url']}");
            }
        }

        if (!empty($analysis['downloadable']['newspapers_urls'])) {
            $this->newLine();
            $this->comment('Newspapers.com URLs:');
            foreach (array_slice($analysis['downloadable']['newspapers_urls'], 0, 3) as $item) {
                $this->line("  " . substr($item['url'], 0, 100) . "...");
            }
        }

        if (!empty($analysis['downloadable']['other_urls'])) {
            $this->newLine();
            $this->comment('Other URLs:');
            foreach (array_slice($analysis['downloadable']['other_urls'], 0, 3) as $item) {
                $this->line("  " . substr($item['url'], 0, 100) . "...");
            }
        }
    }

    /**
     * Link downloaded media to source
     */
    protected function linkToSource(int $treeId, int $mediaId, array $item): void
    {
        if (empty($item['source_gedcom_id'])) {
            return;
        }

        // Find source by GEDCOM ID
        $source = DB::selectOne(
            "SELECT id FROM genealogy_sources WHERE tree_id = ? AND gedcom_id = ?",
            [$treeId, $item['source_gedcom_id']]
        );

        if ($source) {
            // Find person by GEDCOM ID if available
            $personId = null;
            if (!empty($item['person_gedcom_id'])) {
                $person = DB::selectOne(
                    "SELECT id FROM genealogy_persons WHERE tree_id = ? AND gedcom_id = ?",
                    [$treeId, $item['person_gedcom_id']]
                );
                if ($person) {
                    $personId = $person->id;
                }
            }

            $this->downloadService->linkMediaToSource(
                $source->id,
                $mediaId,
                $personId,
                $item['page'] ?? null
            );
        }
    }
}
