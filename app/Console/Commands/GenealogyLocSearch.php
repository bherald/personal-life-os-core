<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMediaDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command to search and download historical newspapers from LOC Chronicling America
 *
 * E20: Family Tree App - LOC Chronicling America Integration
 *
 * Coverage: 1736-1963 historical newspapers (free, no auth required)
 */
class GenealogyLocSearch extends Command
{
    protected $signature = 'genealogy:loc-search
                            {tree_id : Tree ID for storage}
                            {--query= : Search query (person name, event, etc.)}
                            {--person= : Person ID to search for (uses name and death info)}
                            {--state= : State filter (e.g., Pennsylvania)}
                            {--date-start= : Start year for date filter}
                            {--date-end= : End year for date filter}
                            {--obituary : Search specifically for obituaries}
                            {--limit=25 : Maximum results to return}
                            {--download : Download matching newspaper pages}
                            {--resolution=high : Download resolution (thumbnail, medium, high, full)}';

    protected $description = 'Search LOC Chronicling America for historical newspaper pages (1736-1963)';

    protected GenealogyMediaDownloadService $downloadService;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $treeId = (int) $this->argument('tree_id');
        $query = $this->option('query');
        $personId = $this->option('person');
        $state = $this->option('state');
        $dateStart = $this->option('date-start');
        $dateEnd = $this->option('date-end');
        $obituaryMode = $this->option('obituary');
        $limit = (int) $this->option('limit');
        $download = $this->option('download');
        $resolution = $this->option('resolution');

        $this->downloadService = new GenealogyMediaDownloadService();

        // Verify tree exists
        $tree = DB::selectOne("SELECT * FROM genealogy_trees WHERE id = ?", [$treeId]);
        if (!$tree) {
            $this->error("Tree ID {$treeId} not found");
            return Command::FAILURE;
        }

        $this->info("Tree: {$tree->name}");
        $this->newLine();

        // Build search query
        if ($personId) {
            // Get person details for search
            $person = DB::selectOne(
                "SELECT given_name, surname, death_date FROM genealogy_persons WHERE id = ? AND tree_id = ?",
                [$personId, $treeId]
            );

            if (!$person) {
                $this->error("Person ID {$personId} not found in tree");
                return Command::FAILURE;
            }

            $this->info("Searching for: {$person->given_name} {$person->surname}");

            if ($obituaryMode) {
                // Extract death year if available
                $deathYear = null;
                if ($person->death_date) {
                    if (preg_match('/(\d{4})/', $person->death_date, $match)) {
                        $deathYear = $match[1];
                        $this->info("Death year: {$deathYear}");
                    }
                }

                $results = $this->downloadService->searchChroniclingAmericaObituaries(
                    $person->surname,
                    $person->given_name,
                    $state,
                    $deathYear
                );
            } else {
                $query = "\"{$person->given_name} {$person->surname}\"";
                $results = $this->downloadService->searchChroniclingAmerica($query, [
                    'state' => $state,
                    'dateStart' => $dateStart,
                    'dateEnd' => $dateEnd,
                    'limit' => $limit,
                ]);
            }
        } elseif ($query) {
            $this->info("Searching for: {$query}");

            if ($obituaryMode) {
                // Parse query as name for obituary search
                $parts = explode(' ', trim($query));
                $surname = array_pop($parts);
                $givenName = implode(' ', $parts) ?: null;

                $results = $this->downloadService->searchChroniclingAmericaObituaries(
                    $surname,
                    $givenName,
                    $state,
                    $dateStart // Use as death year for obituary mode
                );
            } else {
                $results = $this->downloadService->searchChroniclingAmerica($query, [
                    'state' => $state,
                    'dateStart' => $dateStart,
                    'dateEnd' => $dateEnd,
                    'limit' => $limit,
                ]);
            }
        } else {
            $this->error('Either --query or --person is required');
            return Command::FAILURE;
        }

        // Display results
        if (!$results['success']) {
            $this->error('Search failed:');
            foreach ($results['errors'] as $error) {
                $this->line("  - {$error}");
            }
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("Found {$results['total']} total results, showing " . count($results['pages']));
        $this->newLine();

        if (empty($results['pages'])) {
            $this->warn('No newspaper pages found matching your search.');
            $this->comment('Tips:');
            $this->line('  - Try broader search terms');
            $this->line('  - Check spelling of names');
            $this->line('  - Expand date range');
            $this->line('  - LOC coverage is 1736-1963');
            return Command::SUCCESS;
        }

        // Display results table
        $tableData = [];
        foreach ($results['pages'] as $index => $page) {
            $tableData[] = [
                $index + 1,
                $page['date'],
                substr($page['title'], 0, 40),
                $page['city'] . ', ' . strtoupper(substr($page['state'], 0, 2)),
                count($page['image_urls']) . ' img',
            ];
        }

        $this->table(
            ['#', 'Date', 'Newspaper', 'Location', 'Images'],
            $tableData
        );

        // Show snippets if available
        $this->newLine();
        $this->comment('Result previews:');
        foreach (array_slice($results['pages'], 0, 5) as $index => $page) {
            $this->line(($index + 1) . ". {$page['title']} ({$page['date']})");
            if (!empty($page['description'])) {
                $desc = strip_tags($page['description']);
                $desc = preg_replace('/\s+/', ' ', $desc);
                $this->line("   " . substr($desc, 0, 150) . "...");
            }
            $this->line("   URL: {$page['url']}");
            $this->newLine();
        }

        // Download if requested
        if ($download) {
            $this->newLine();
            $this->info("Downloading newspaper pages (resolution: {$resolution})...");

            $downloaded = 0;
            $failed = 0;

            foreach ($results['pages'] as $index => $page) {
                if ($downloaded >= $limit) {
                    break;
                }

                $num = $downloaded + 1;
                $this->line("  [{$num}] {$page['title']} ({$page['date']})");

                // Download the first image URL
                if (!empty($page['image_urls'])) {
                    $imageUrl = $page['image_urls'][0];
                    $downloadResult = $this->downloadService->downloadChroniclingAmericaPage(
                        $imageUrl,
                        $treeId,
                        $resolution
                    );

                    if ($downloadResult['success'] && !empty($downloadResult['files'])) {
                        $file = $downloadResult['files'][0];
                        $downloaded++;
                        $this->info("      Downloaded: {$file['filename']} (" . round($file['size'] / 1024) . " KB)");

                        // Create media record
                        $mediaId = $this->downloadService->createMediaRecord($treeId, $file['filepath'], [
                            'title' => "{$page['title']} - {$page['date']}",
                            'source' => 'LOC Chronicling America',
                            'source_url' => $page['url'],
                            'description' => "Historical newspaper from {$page['city']}, {$page['state']}. " .
                                "Date: {$page['date']}. Source: Library of Congress Chronicling America.",
                        ]);

                        $this->comment("      Media ID: {$mediaId}");
                    } else {
                        $failed++;
                        $error = $downloadResult['errors'][0] ?? 'Unknown error';
                        $this->warn("      Failed: {$error}");
                    }

                    // Rate limiting - be respectful of LOC servers
                    sleep(1);
                }
            }

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

            Log::info('LOC Chronicling America download completed', [
                'tree_id' => $treeId,
                'query' => $query ?? "person:{$personId}",
                'downloaded' => $downloaded,
                'failed' => $failed,
            ]);
        }

        return Command::SUCCESS;
    }
}
