<?php

namespace App\Console\Commands;

use App\Services\AIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GenealogyEmbedPersonsCommand
 *
 * Generates 768d vector embeddings for genealogy persons and stores them in the
 * PostgreSQL genealogy_person_embeddings table, enabling semantic person search
 * and future face-to-person matching.
 *
 * Skips living persons (privacy). Upserts on person_id conflict.
 */
class GenealogyEmbedPersonsCommand extends Command
{
    protected $signature = 'genealogy:embed-persons
                            {--tree-id= : Specific tree ID to process}
                            {--limit=200 : Max persons per run}
                            {--reindex : Re-embed all persons (delete existing embeddings first)}
                            {--stats : Show counts only, no processing}';

    protected $description = 'Generate 768d embeddings for genealogy persons into genealogy_person_embeddings';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $treeId  = $this->option('tree-id') ? (int) $this->option('tree-id') : null;
        $limit   = (int) $this->option('limit');
        $reindex = (bool) $this->option('reindex');

        $this->info('Genealogy Person Embeddings');
        $this->info('===========================');
        $this->newLine();

        if ($reindex) {
            $this->warn('--reindex: deleting existing embeddings' . ($treeId ? " for tree {$treeId}" : ' (all trees)'));
            $this->deleteExisting($treeId);
            $this->newLine();
        }

        // Fetch persons needing embeddings
        $persons = $this->fetchPersons($treeId, $limit, $reindex);

        if (empty($persons)) {
            $this->info('No persons require embedding.');
            $this->line('[ITEMS_PROCESSED:0]');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Processing %d persons (limit %d)...', count($persons), $limit));
        $this->newLine();

        $aiService = app(AIService::class);
        $bar = $this->output->createProgressBar(count($persons));
        $bar->start();

        $embedded  = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($persons as $person) {
            try {
                $searchText = $this->buildSearchText($person);
                $biography  = $this->buildBiography($person, $searchText);

                $result = $aiService->generateEmbedding($searchText);

                if (! ($result['success'] ?? false) || empty($result['embedding'])) {
                    $failed++;
                    Log::warning('genealogy:embed-persons — embedding failed', [
                        'person_id' => $person->id,
                        'name'      => trim($person->given_name . ' ' . $person->surname),
                    ]);
                    $bar->advance();
                    continue;
                }

                $embeddingStr = '[' . implode(',', $result['embedding']) . ']';
                $birthYear    = $this->extractYear($person->birth_date ?? null);
                $deathYear    = $this->extractYear($person->death_date ?? null);
                $fullName     = trim(($person->given_name ?? '') . ' ' . ($person->surname ?? ''));

                DB::connection('pgsql_rag')->statement("
                    INSERT INTO genealogy_person_embeddings
                        (person_id, tree_id, full_name, surname, given_name,
                         birth_year, death_year, birth_place, death_place,
                         biography, search_text, embedding, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::vector, NOW(), NOW())
                    ON CONFLICT (person_id) DO UPDATE SET
                        full_name    = EXCLUDED.full_name,
                        search_text  = EXCLUDED.search_text,
                        biography    = EXCLUDED.biography,
                        embedding    = EXCLUDED.embedding,
                        birth_year   = EXCLUDED.birth_year,
                        death_year   = EXCLUDED.death_year,
                        birth_place  = EXCLUDED.birth_place,
                        death_place  = EXCLUDED.death_place,
                        updated_at   = NOW()
                ", [
                    $person->id,
                    $person->tree_id,
                    $fullName,
                    $person->surname  ?? null,
                    $person->given_name ?? null,
                    $birthYear,
                    $deathYear,
                    $person->birth_place ?? null,
                    $person->death_place ?? null,
                    $biography,
                    $searchText,
                    $embeddingStr,
                ]);

                $embedded++;
            } catch (\Exception $e) {
                $failed++;
                Log::error('genealogy:embed-persons — upsert error', [
                    'person_id' => $person->id,
                    'error'     => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Final stats table
        $totalEmbedded = $this->countEmbedded($treeId);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed this run',    count($persons)],
                ['Embedded',              $embedded],
                ['Skipped (no embedding)', $skipped],
                ['Failed',                $failed],
                ['Total in table',        $totalEmbedded],
            ]
        );

        $this->newLine();
        $this->line(sprintf('[ITEMS_PROCESSED:%d]', $embedded));

        Log::info('genealogy:embed-persons completed', [
            'tree_id'       => $treeId,
            'processed'     => count($persons),
            'embedded'      => $embedded,
            'failed'        => $failed,
            'total_in_table'=> $totalEmbedded,
        ]);

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Stats mode
    // -------------------------------------------------------------------------

    private function showStats(): int
    {
        $this->info('Genealogy Person Embeddings — Stats');
        $this->info('====================================');
        $this->newLine();

        // Persons eligible (non-living)
        $totalPersons = DB::select(
            'SELECT COUNT(*) AS cnt FROM genealogy_persons WHERE living = 0 OR living IS NULL'
        )[0]->cnt ?? 0;

        // Already embedded
        $totalEmbedded = DB::connection('pgsql_rag')->select(
            'SELECT COUNT(*) AS cnt FROM genealogy_person_embeddings'
        )[0]->cnt ?? 0;

        $pending = max(0, $totalPersons - $totalEmbedded);

        // Per-tree breakdown (MySQL side)
        $treeCounts = DB::select(
            'SELECT tree_id, COUNT(*) AS cnt FROM genealogy_persons
             WHERE living = 0 OR living IS NULL
             GROUP BY tree_id ORDER BY tree_id'
        );

        $embeddedByTree = DB::connection('pgsql_rag')->select(
            'SELECT tree_id, COUNT(*) AS cnt FROM genealogy_person_embeddings GROUP BY tree_id ORDER BY tree_id'
        );

        $embMap = [];
        foreach ($embeddedByTree as $row) {
            $embMap[(int) $row->tree_id] = (int) $row->cnt;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total eligible persons (non-living)', $totalPersons],
                ['Embeddings stored',                   $totalEmbedded],
                ['Pending (approx)',                    $pending],
            ]
        );

        if (! empty($treeCounts)) {
            $this->newLine();
            $this->info('Per-tree breakdown:');
            $rows = [];
            foreach ($treeCounts as $row) {
                $tid = (int) $row->tree_id;
                $rows[] = [
                    'tree_id'  => $tid,
                    'persons'  => (int) $row->cnt,
                    'embedded' => $embMap[$tid] ?? 0,
                    'pending'  => max(0, (int) $row->cnt - ($embMap[$tid] ?? 0)),
                ];
            }
            $this->table(['Tree ID', 'Persons', 'Embedded', 'Pending'], $rows);
        }

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch persons that still need embedding from MySQL.
     *
     * Cross-DB join (MySQL + PostgreSQL) is not possible directly, so when
     * skipping already-embedded persons we pull the existing person_id list
     * from PostgreSQL first, then exclude via NOT IN on the MySQL query.
     * The set is bounded by $limit to keep the IN-list manageable.
     */
    private function fetchPersons(?int $treeId, int $limit, bool $reindex): array
    {
        $params = [];

        $sql = 'SELECT p.id, p.tree_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place,
                       p.occupation, p.notes
                FROM genealogy_persons p
                WHERE (p.living = 0 OR p.living IS NULL)';

        if ($treeId !== null) {
            $sql .= ' AND p.tree_id = ?';
            $params[] = $treeId;
        }

        if (! $reindex) {
            // Fetch already-embedded IDs from PostgreSQL
            $pgSql = 'SELECT person_id FROM genealogy_person_embeddings';
            $pgParams = [];

            if ($treeId !== null) {
                $pgSql .= ' WHERE tree_id = ?';
                $pgParams[] = $treeId;
            }

            $existing = DB::connection('pgsql_rag')->select($pgSql, $pgParams);

            if (! empty($existing)) {
                $existingIds = array_map(fn($row) => (int) $row->person_id, $existing);
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                $sql .= " AND p.id NOT IN ({$placeholders})";
                array_push($params, ...$existingIds);
            }
        }

        $sql .= ' ORDER BY p.id ASC LIMIT ?';
        $params[] = $limit;

        return DB::select($sql, $params);
    }

    /**
     * Delete existing embeddings (used with --reindex).
     */
    private function deleteExisting(?int $treeId): void
    {
        if ($treeId !== null) {
            $deleted = DB::connection('pgsql_rag')->delete(
                'DELETE FROM genealogy_person_embeddings WHERE tree_id = ?',
                [$treeId]
            );
        } else {
            $deleted = DB::connection('pgsql_rag')->delete(
                'DELETE FROM genealogy_person_embeddings'
            );
        }

        $this->line(sprintf('Deleted %d existing embedding(s).', $deleted));
    }

    /**
     * Count total rows in genealogy_person_embeddings (optionally filtered by tree).
     */
    private function countEmbedded(?int $treeId): int
    {
        if ($treeId !== null) {
            $row = DB::connection('pgsql_rag')->select(
                'SELECT COUNT(*) AS cnt FROM genealogy_person_embeddings WHERE tree_id = ?',
                [$treeId]
            );
        } else {
            $row = DB::connection('pgsql_rag')->select(
                'SELECT COUNT(*) AS cnt FROM genealogy_person_embeddings'
            );
        }

        return (int) ($row[0]->cnt ?? 0);
    }

    /**
     * Build the search_text string from a person row.
     * Only includes fields that are non-null and non-empty.
     */
    private function buildSearchText(object $person): string
    {
        $parts = [];

        $name = trim(($person->given_name ?? '') . ' ' . ($person->surname ?? ''));
        if ($name !== '') {
            $parts[] = $name;
        }

        $sexLabel = match (strtoupper((string) ($person->sex ?? ''))) {
            'M'     => 'male',
            'F'     => 'female',
            default => null,
        };
        if ($sexLabel !== null) {
            $parts[] = $sexLabel;
        }

        if (! empty($person->birth_date)) {
            $born = 'born ' . $person->birth_date;
            if (! empty($person->birth_place)) {
                $born .= ' in ' . $person->birth_place;
            }
            $parts[] = $born;
        } elseif (! empty($person->birth_place)) {
            $parts[] = 'born in ' . $person->birth_place;
        }

        if (! empty($person->death_date)) {
            $died = 'died ' . $person->death_date;
            if (! empty($person->death_place)) {
                $died .= ' in ' . $person->death_place;
            }
            $parts[] = $died;
        } elseif (! empty($person->death_place)) {
            $parts[] = 'died in ' . $person->death_place;
        }

        if (! empty($person->occupation)) {
            $parts[] = 'Occupation: ' . $person->occupation;
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * Build biography = search_text + first 500 chars of notes (if present).
     */
    private function buildBiography(object $person, string $searchText): string
    {
        if (empty($person->notes)) {
            return $searchText;
        }

        $notesSnippet = mb_substr(trim($person->notes), 0, 500);

        return $searchText . "\n" . $notesSnippet;
    }

    /**
     * Extract a 4-digit year from a date string (e.g. "15 MAR 1842", "1842", "1842-03-15").
     */
    private function extractYear(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        if (preg_match('/\b(\d{4})\b/', $date, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
