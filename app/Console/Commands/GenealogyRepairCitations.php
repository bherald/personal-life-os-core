<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GedcomParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair genealogy citations to add missing source-media links
 *
 * This command re-parses the original GEDCOM file and backfills
 * source-citation-media links that were missed in the original import.
 */
class GenealogyRepairCitations extends Command
{
    protected $signature = 'genealogy:repair-citations
                            {tree_id : Tree ID to repair}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Repair missing source-citation-media links from GEDCOM';

    public function handle(): int
    {
        $treeId = (int) $this->argument('tree_id');
        $dryRun = $this->option('dry-run');

        // Get tree info
        $tree = DB::selectOne("SELECT * FROM genealogy_trees WHERE id = ?", [$treeId]);
        if (!$tree) {
            $this->error("Tree ID {$treeId} not found");
            return Command::FAILURE;
        }

        if (empty($tree->source_file) || !file_exists($tree->source_file)) {
            $this->error("Source GEDCOM file not found: {$tree->source_file}");
            $this->info("Please specify the path to the GEDCOM file.");
            return Command::FAILURE;
        }

        $this->info("Repairing citations for tree: {$tree->name}");
        $this->info("Source file: {$tree->source_file}");
        $this->newLine();

        // Parse GEDCOM to get source-citation-media links
        $this->info('Parsing GEDCOM file for source-citation-media links...');
        $parser = new GedcomParserService($tree->source_file);
        $data = $parser->parse();

        $links = $data['source_citation_media'] ?? [];
        $this->info("Found " . count($links) . " source-citation-media links in GEDCOM");

        if (empty($links)) {
            $this->warn("No source-citation-media links found in GEDCOM file.");
            return Command::SUCCESS;
        }

        // Build GEDCOM ID to database ID mappings
        $this->info('Building ID mappings...');

        // Map GEDCOM source IDs to database IDs
        $sourceIdMap = [];
        $sources = DB::select("SELECT id, gedcom_id FROM genealogy_sources WHERE tree_id = ?", [$treeId]);
        foreach ($sources as $s) {
            $sourceIdMap[$s->gedcom_id] = $s->id;
        }
        $this->info("  Sources: " . count($sourceIdMap));

        // Map GEDCOM media IDs to database IDs
        $mediaIdMap = [];
        $media = DB::select("SELECT id, gedcom_id FROM genealogy_media WHERE tree_id = ?", [$treeId]);
        foreach ($media as $m) {
            $mediaIdMap[$m->gedcom_id] = $m->id;
        }
        $this->info("  Media: " . count($mediaIdMap));

        // Map GEDCOM person IDs to database IDs
        $personIdMap = [];
        $persons = DB::select("SELECT id, gedcom_id FROM genealogy_persons WHERE tree_id = ?", [$treeId]);
        foreach ($persons as $p) {
            $personIdMap[$p->gedcom_id] = $p->id;
        }
        $this->info("  Persons: " . count($personIdMap));

        // Count existing citations with media
        $existingCount = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM genealogy_citations c
             JOIN genealogy_sources s ON c.source_id = s.id
             WHERE s.tree_id = ? AND c.media_id IS NOT NULL",
            [$treeId]
        )->cnt;
        $this->info("Existing citation-media links in database: {$existingCount}");
        $this->newLine();

        if ($dryRun) {
            $this->warn("DRY RUN - No changes will be made");
            $this->newLine();
        }

        // Process links
        $added = 0;
        $skipped = 0;
        $notFound = 0;

        $progressBar = $this->output->createProgressBar(count($links));
        $progressBar->start();

        foreach ($links as $link) {
            $sourceDbId = $sourceIdMap[$link['source_gedcom_id']] ?? null;
            $mediaDbId = $mediaIdMap[$link['media_gedcom_id']] ?? null;
            $personDbId = isset($link['person_gedcom_id']) ? ($personIdMap[$link['person_gedcom_id']] ?? null) : null;

            if (!$sourceDbId || !$mediaDbId) {
                $notFound++;
                $progressBar->advance();
                continue;
            }

            // Check if link already exists
            $existing = DB::selectOne(
                "SELECT id FROM genealogy_citations
                 WHERE source_id = ? AND media_id = ? AND (person_id = ? OR (person_id IS NULL AND ? IS NULL))",
                [$sourceDbId, $mediaDbId, $personDbId, $personDbId]
            );

            if ($existing) {
                $skipped++;
            } else {
                if (!$dryRun) {
                    DB::insert(
                        "INSERT INTO genealogy_citations
                         (source_id, media_id, person_id, page, fact_type, created_at)
                         VALUES (?, ?, ?, ?, 'source_media', NOW())",
                        [$sourceDbId, $mediaDbId, $personDbId, $link['citation_page'] ?? null]
                    );
                }
                $added++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total links in GEDCOM', count($links)],
                ['Already existed', $skipped],
                ['Added' . ($dryRun ? ' (would add)' : ''), $added],
                ['IDs not found', $notFound],
            ]
        );

        // Show some examples of what was added
        if ($added > 0 && !$dryRun) {
            $this->newLine();
            $this->info('Sample of newly added citation-media links:');
            $samples = DB::select(
                "SELECT c.id, s.title as source_title, m.title as media_title, p.given_name, p.surname
                 FROM genealogy_citations c
                 JOIN genealogy_sources s ON c.source_id = s.id
                 JOIN genealogy_media m ON c.media_id = m.id
                 LEFT JOIN genealogy_persons p ON c.person_id = p.id
                 WHERE s.tree_id = ? AND c.fact_type = 'source_media'
                 ORDER BY c.id DESC
                 LIMIT 5",
                [$treeId]
            );
            foreach ($samples as $sample) {
                $personName = $sample->given_name ? "{$sample->given_name} {$sample->surname}" : '(no person)';
                $this->line("  - {$sample->source_title} → {$sample->media_title} ({$personName})");
            }
        }

        Log::info('Genealogy citation repair completed', [
            'tree_id' => $treeId,
            'dry_run' => $dryRun,
            'added' => $added,
            'skipped' => $skipped,
            'not_found' => $notFound,
        ]);

        return Command::SUCCESS;
    }
}
