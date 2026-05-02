<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DI-3: Index genealogy person records into RAG for cross-domain search.
 *
 * Builds a rich text profile per person (name, dates, places, family, events, sources)
 * and indexes it via RAGService. Uses rag_indexed_at to track which persons need indexing.
 *
 * Usage:
 *   php artisan genealogy:rag-index --limit=50           # Index unindexed persons
 *   php artisan genealogy:rag-index --reindex --limit=20  # Re-index all (force)
 *   php artisan genealogy:rag-index --stats               # Show indexing stats
 *   php artisan genealogy:rag-index --dry-run --limit=5   # Preview without indexing
 */
class GenealogyRagIndexCommand extends Command
{
    protected $signature = 'genealogy:rag-index
                            {--limit=50 : Max items to index per run}
                            {--type=persons : Type: persons, places, sources, all}
                            {--reindex : Re-index already indexed items}
                            {--stats : Show indexing statistics}
                            {--dry-run : Preview without indexing}
                            {--tree= : Limit to specific tree_id}';

    protected $description = 'DI-3/DI-9: Index genealogy data into RAG (persons, places, sources)';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $type = $this->option('type');
        $limit = (int) $this->option('limit');
        $reindex = $this->option('reindex');
        $dryRun = $this->option('dry-run');
        $treeId = $this->option('tree');

        $totalIndexed = 0;

        if ($type === 'all' || $type === 'places') {
            $totalIndexed += $this->indexPlaces($limit, $reindex, $dryRun, $treeId);
        }

        if ($type === 'all' || $type === 'sources') {
            $totalIndexed += $this->indexSources($limit, $reindex, $dryRun, $treeId);
        }

        if ($type !== 'places' && $type !== 'sources') {
            // Default: persons (original behavior)
        } else {
            $this->info("Total indexed: {$totalIndexed}. [ITEMS_PROCESSED:{$totalIndexed}]");

            return 0;
        }

        $this->info("Genealogy RAG indexing persons (limit: {$limit}, reindex: ".($reindex ? 'yes' : 'no').')');

        $persons = $this->getPersonsToIndex($limit, $reindex, $treeId);

        if (empty($persons)) {
            $this->info('No persons to index.');

            return 0;
        }

        $this->info('Found '.count($persons).' persons to index.');

        $ragService = app(RAGService::class);
        $indexed = 0;
        $failed = 0;
        $startTime = microtime(true);

        foreach ($persons as $person) {
            $name = trim(($person->given_name ?? '').' '.($person->surname ?? ''));

            try {
                $content = $this->buildPersonContent($person);

                if (strlen(trim($content)) < 50) {
                    $this->line("  Skip (too little data): {$name}");

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY] Would index: {$name} (".strlen($content).' chars)');
                    $indexed++;

                    continue;
                }

                $doc = $ragService->indexDocument(
                    'genealogy_person',
                    $content,
                    $name,
                    [
                        'person_id' => $person->id,
                        'tree_id' => $person->tree_id,
                        'birth_year' => $this->extractYear($person->birth_date),
                        'death_year' => $this->extractYear($person->death_date),
                        'birth_place' => $person->birth_place,
                    ],
                    $person->id,
                    'genealogy_person',
                    'genealogy'
                );

                if ($doc) {
                    DB::update('UPDATE genealogy_persons SET rag_indexed_at = NOW() WHERE id = ?', [$person->id]);
                    $indexed++;
                    $this->line("  OK: {$name} (doc #{$doc->id})");
                } else {
                    $failed++;
                    $this->line("  <error>Dedup/skip:</error> {$name}");
                }

            } catch (\Throwable $e) {
                $failed++;
                $this->line("  <error>Error:</error> {$name} — {$e->getMessage()}");
                Log::warning('GenealogyRagIndex: Failed to index person', [
                    'person_id' => $person->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Wall-clock limit: 10 minutes
            if ((microtime(true) - $startTime) > 600) {
                $this->warn('Wall-clock limit reached (10 min).');
                break;
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Indexed', $dryRun ? "{$indexed} (dry run)" : $indexed],
            ['Failed', $failed],
            ['Duration', round(microtime(true) - $startTime, 1).'s'],
        ]);

        return $failed > 0 && $indexed === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function getPersonsToIndex(int $limit, bool $reindex, ?string $treeId): array
    {
        $sql = 'SELECT p.id, p.tree_id, p.given_name, p.surname, p.suffix, p.nickname,
                       p.sex, p.birth_date, p.birth_place, p.death_date, p.death_place,
                       p.burial_date, p.burial_place, p.occupation, p.religion,
                       p.nationality, p.notes, p.cause_of_death
                FROM genealogy_persons p
                WHERE p.living = 0';

        $params = [];

        if (! $reindex) {
            $sql .= ' AND p.rag_indexed_at IS NULL';
        }

        if ($treeId) {
            $sql .= ' AND p.tree_id = ?';
            $params[] = $treeId;
        }

        $sql .= ' ORDER BY p.updated_at DESC LIMIT ?';
        $params[] = $limit;

        return DB::select($sql, $params);
    }

    private function buildPersonContent(object $person): string
    {
        $parts = [];
        $name = trim(($person->given_name ?? '').' '.($person->surname ?? ''));
        if ($person->suffix) {
            $name .= " {$person->suffix}";
        }

        $parts[] = "Person: {$name}";
        if ($person->nickname) {
            $parts[] = "Also known as: {$person->nickname}";
        }
        if ($person->sex) {
            $parts[] = 'Sex: '.match ($person->sex) {
                'M' => 'Male', 'F' => 'Female', default => $person->sex
            };
        }

        if ($person->birth_date) {
            $parts[] = "Born: {$person->birth_date}".($person->birth_place ? " in {$person->birth_place}" : '');
        }
        if ($person->death_date) {
            $parts[] = "Died: {$person->death_date}".($person->death_place ? " in {$person->death_place}" : '');
        }
        if ($person->burial_place) {
            $parts[] = 'Buried: '.($person->burial_date ? "{$person->burial_date} " : '')."at {$person->burial_place}";
        }

        if ($person->occupation) {
            $parts[] = "Occupation: {$person->occupation}";
        }
        if ($person->religion) {
            $parts[] = "Religion: {$person->religion}";
        }
        if ($person->nationality) {
            $parts[] = "Nationality: {$person->nationality}";
        }
        if ($person->cause_of_death) {
            $parts[] = "Cause of death: {$person->cause_of_death}";
        }

        // Family relationships
        $families = $this->getPersonFamilies($person->id);
        if (! empty($families)) {
            $parts[] = "\nFamily:";
            foreach ($families as $fam) {
                $parts[] = "- {$fam}";
            }
        }

        // Events
        $events = $this->getPersonEvents($person->id);
        if (! empty($events)) {
            $parts[] = "\nLife Events:";
            foreach ($events as $evt) {
                $parts[] = "- {$evt}";
            }
        }

        // Sources
        $sources = $this->getPersonSources($person->id);
        if (! empty($sources)) {
            $parts[] = "\nSources:";
            foreach (array_slice($sources, 0, 10) as $src) {
                $parts[] = "- {$src}";
            }
        }

        if ($person->notes) {
            $parts[] = "\nNotes: ".mb_substr($person->notes, 0, 500);
        }

        return implode("\n", $parts);
    }

    private function getPersonFamilies(int $personId): array
    {
        $families = [];

        try {
            // Spouse relationships
            $spouses = DB::select('
                SELECT p2.given_name, p2.surname, f.marriage_date, f.marriage_place
                FROM genealogy_families f
                JOIN genealogy_persons p2 ON (
                    (f.husband_id = ? AND f.wife_id = p2.id) OR
                    (f.wife_id = ? AND f.husband_id = p2.id)
                )
                WHERE f.husband_id = ? OR f.wife_id = ?
            ', [$personId, $personId, $personId, $personId]);

            foreach ($spouses as $s) {
                $spouseName = trim(($s->given_name ?? '').' '.($s->surname ?? ''));
                $detail = "Spouse: {$spouseName}";
                if ($s->marriage_date) {
                    $detail .= " (married {$s->marriage_date}".($s->marriage_place ? " in {$s->marriage_place}" : '').')';
                }
                $families[] = $detail;
            }

            // Children
            $children = DB::select('
                SELECT p.given_name, p.surname, p.birth_date
                FROM genealogy_persons p
                JOIN genealogy_children gc ON gc.person_id = p.id
                JOIN genealogy_families f ON f.id = gc.family_id
                WHERE f.husband_id = ? OR f.wife_id = ?
                ORDER BY p.birth_date
            ', [$personId, $personId]);

            foreach ($children as $c) {
                $childName = trim(($c->given_name ?? '').' '.($c->surname ?? ''));
                $families[] = "Child: {$childName}".($c->birth_date ? " (b. {$c->birth_date})" : '');
            }

            // Parents
            $parents = DB::select("
                SELECT p.given_name, p.surname,
                       CASE WHEN f.husband_id = p.id THEN 'Father' ELSE 'Mother' END as relationship
                FROM genealogy_families f
                JOIN genealogy_children gc ON gc.family_id = f.id
                JOIN genealogy_persons p ON p.id IN (f.husband_id, f.wife_id)
                WHERE gc.person_id = ?
            ", [$personId]);

            foreach ($parents as $p) {
                $parentName = trim(($p->given_name ?? '').' '.($p->surname ?? ''));
                $families[] = "{$p->relationship}: {$parentName}";
            }

        } catch (\Throwable $e) {
            // Non-critical
        }

        return $families;
    }

    private function getPersonEvents(int $personId): array
    {
        try {
            $events = DB::select('
                SELECT event_type, event_date, event_place, description
                FROM genealogy_events
                WHERE person_id = ?
                ORDER BY event_date
            ', [$personId]);

            return array_map(function ($e) {
                $detail = ucfirst($e->event_type);
                if ($e->event_date) {
                    $detail .= ": {$e->event_date}";
                }
                if ($e->event_place) {
                    $detail .= " in {$e->event_place}";
                }
                if ($e->description) {
                    $detail .= " — {$e->description}";
                }

                return $detail;
            }, $events);

        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getPersonSources(int $personId): array
    {
        try {
            $sources = DB::select('
                SELECT gs.title, gs.author, gs.repository
                FROM genealogy_citations gc
                JOIN genealogy_sources gs ON gs.id = gc.source_id
                WHERE gc.person_id = ?
                LIMIT 10
            ', [$personId]);

            return array_map(function ($s) {
                $detail = $s->title ?? 'Untitled';
                if ($s->author) {
                    $detail .= " by {$s->author}";
                }
                if ($s->repository) {
                    $detail .= " ({$s->repository})";
                }

                return $detail;
            }, $sources);

        } catch (\Throwable $e) {
            return [];
        }
    }

    private function showStats(): int
    {
        $stats = DB::selectOne('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) as indexed,
                SUM(CASE WHEN rag_indexed_at IS NULL AND living = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN living = 1 THEN 1 ELSE 0 END) as living_excluded
            FROM genealogy_persons
        ');

        $this->table(['Metric', 'Value'], [
            ['Total persons', $stats->total],
            ['RAG indexed', $stats->indexed],
            ['Pending (non-living)', $stats->pending],
            ['Living (excluded)', $stats->living_excluded],
        ]);

        return 0;
    }

    private function extractYear(?string $date): ?int
    {
        if (! $date) {
            return null;
        }
        if (preg_match('/(\d{4})/', $date, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * DI-9: Index genealogy places into RAG
     */
    private function indexPlaces(int $limit, bool $reindex, bool $dryRun, ?string $treeId): int
    {
        $sql = 'SELECT id, name, normalized_name, place_type, latitude, longitude
                FROM genealogy_places WHERE 1=1';
        $params = [];
        if (! $reindex) {
            $sql .= ' AND rag_indexed_at IS NULL';
        }
        $sql .= ' ORDER BY id ASC LIMIT ?';
        $params[] = $limit;

        try {
            $places = DB::select($sql, $params);
        } catch (\Throwable $e) {
            // rag_indexed_at column may not exist yet
            $this->warn("Places indexing skipped: {$e->getMessage()}");

            return 0;
        }

        if (empty($places)) {
            $this->info('No places to index.');

            return 0;
        }

        $this->info('Found '.count($places).' places to index.');
        $ragService = app(RAGService::class);
        $indexed = 0;

        foreach ($places as $place) {
            $content = "Place: {$place->name}";
            if ($place->normalized_name && $place->normalized_name !== $place->name) {
                $content .= " (standardized: {$place->normalized_name})";
            }
            if ($place->place_type) {
                $content .= "\nType: {$place->place_type}";
            }
            if ($place->latitude && $place->longitude) {
                $content .= "\nCoordinates: {$place->latitude}, {$place->longitude}";
            }

            if (strlen($content) < 20) {
                continue;
            }

            if ($dryRun) {
                $this->line("  Would index place: {$place->name}");
                $indexed++;

                continue;
            }

            try {
                $ragService->indexDocument([
                    'title' => "Place: {$place->name}",
                    'content' => $content,
                    'source' => 'genealogy_place',
                    'source_id' => (string) $place->id,
                    'metadata' => json_encode(['type' => $place->place_type]),
                ]);
                DB::update('UPDATE genealogy_places SET rag_indexed_at = NOW() WHERE id = ?', [$place->id]);
                $indexed++;
            } catch (\Throwable $e) {
                Log::warning('GenealogyRagIndex: Place indexing failed', ['id' => $place->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Places: {$indexed} indexed.");

        return $indexed;
    }

    /**
     * DI-9: Index genealogy sources into RAG
     */
    private function indexSources(int $limit, bool $reindex, bool $dryRun, ?string $treeId): int
    {
        $sql = 'SELECT gs.id, gs.tree_id, gs.title, gs.author, gs.publication,
                       gs.source_category, gs.repository, gs.url, gs.notes
                FROM genealogy_sources gs WHERE 1=1';
        $params = [];

        if ($treeId) {
            $sql .= ' AND gs.tree_id = ?';
            $params[] = $treeId;
        }
        if (! $reindex) {
            $sql .= ' AND gs.rag_indexed_at IS NULL';
        }
        $sql .= ' ORDER BY gs.id ASC LIMIT ?';
        $params[] = $limit;

        try {
            $sources = DB::select($sql, $params);
        } catch (\Throwable $e) {
            $this->warn("Sources indexing skipped: {$e->getMessage()}");

            return 0;
        }

        if (empty($sources)) {
            $this->info('No sources to index.');

            return 0;
        }

        $this->info('Found '.count($sources).' sources to index.');
        $ragService = app(RAGService::class);
        $indexed = 0;

        foreach ($sources as $src) {
            $parts = ["Source: {$src->title}"];
            if ($src->author) {
                $parts[] = "Author: {$src->author}";
            }
            if ($src->publication) {
                $parts[] = "Publication: {$src->publication}";
            }
            if ($src->source_category) {
                $parts[] = "Category: {$src->source_category}";
            }
            if ($src->repository) {
                $parts[] = "Repository: {$src->repository}";
            }
            if ($src->url) {
                $parts[] = "URL: {$src->url}";
            }
            if ($src->notes) {
                $parts[] = "\nNotes: ".mb_substr($src->notes, 0, 500);
            }

            $content = implode("\n", $parts);
            if (strlen($content) < 20) {
                continue;
            }

            if ($dryRun) {
                $this->line("  Would index source: {$src->title}");
                $indexed++;

                continue;
            }

            try {
                $ragService->indexDocument([
                    'title' => "Source: {$src->title}",
                    'content' => $content,
                    'source' => 'genealogy_source',
                    'source_id' => (string) $src->id,
                    'metadata' => json_encode(['tree_id' => $src->tree_id, 'category' => $src->source_category]),
                ]);
                DB::update('UPDATE genealogy_sources SET rag_indexed_at = NOW() WHERE id = ?', [$src->id]);
                $indexed++;
            } catch (\Throwable $e) {
                Log::warning('GenealogyRagIndex: Source indexing failed', ['id' => $src->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Sources: {$indexed} indexed.");

        return $indexed;
    }
}
