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
 * Indexes all persons by default for private family-tree search. Operators can
 * pass --exclude-living when exporting or running in a privacy-sensitive context.
 */
class GenealogyEmbedPersonsCommand extends Command
{
    protected $signature = 'genealogy:embed-persons
                            {--tree-id= : Specific tree ID to process}
                            {--limit=200 : Max persons per run; 0 means all}
                            {--reindex : Re-embed all persons (delete existing embeddings first)}
                            {--exclude-living : Exclude persons explicitly marked living}
                            {--stats : Show counts only, no processing}';

    protected $description = 'Generate 768d embeddings for genealogy persons into genealogy_person_embeddings';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $treeId = $this->option('tree-id') ? (int) $this->option('tree-id') : null;
        $limit = (int) $this->option('limit');
        $reindex = (bool) $this->option('reindex');
        $excludeLiving = (bool) $this->option('exclude-living');

        $this->info('Genealogy Person Embeddings');
        $this->info('===========================');
        $this->newLine();

        // Fetch persons needing embeddings
        $persons = $this->fetchPersons($treeId, $limit, $reindex, $excludeLiving);

        if (empty($persons)) {
            $this->info('No persons require embedding.');
            $this->syncEmbeddingCoverage($treeId);
            $this->line('[ITEMS_PROCESSED:0]');

            return Command::SUCCESS;
        }

        if ($reindex) {
            $this->warn('--reindex: deleting existing embeddings for selected batch');
            $deleted = $this->deleteExisting(array_map(
                static fn (object $person): int => (int) $person->id,
                $persons
            ));
            $this->line(sprintf('Deleted %d existing embedding(s).', $deleted));
            $this->newLine();
        }

        $this->info(sprintf(
            'Processing %d persons (limit %s)...',
            count($persons),
            $limit <= 0 ? 'all' : (string) $limit
        ));
        $this->newLine();

        $aiService = app(AIService::class);
        $bar = $this->output->createProgressBar(count($persons));
        $bar->start();

        $embedded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($persons as $person) {
            try {
                $searchText = $this->buildSearchText($person);
                $biography = $this->buildBiography($person, $searchText);

                $result = $aiService->generateEmbedding($searchText);

                if (! ($result['success'] ?? false) || empty($result['embedding'])) {
                    $failed++;
                    Log::warning('genealogy:embed-persons — embedding failed', [
                        'person_id' => $person->id,
                        'name' => trim($person->given_name.' '.$person->surname),
                    ]);
                    $bar->advance();

                    continue;
                }

                $embeddingStr = '['.implode(',', $result['embedding']).']';
                $birthYear = $this->extractYear($person->birth_date ?? null);
                $deathYear = $this->extractYear($person->death_date ?? null);
                $fullName = trim(($person->given_name ?? '').' '.($person->surname ?? ''));

                DB::connection('pgsql_rag')->statement('
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
                ', [
                    $person->id,
                    $person->tree_id,
                    $fullName,
                    $person->surname ?? null,
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
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->syncEmbeddingCoverage($treeId);

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
            'tree_id' => $treeId,
            'processed' => count($persons),
            'embedded' => $embedded,
            'failed' => $failed,
            'total_in_table' => $totalEmbedded,
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

        $treeId = $this->option('tree-id') ? (int) $this->option('tree-id') : null;
        $where = $treeId !== null ? ' WHERE tree_id = ?' : '';
        $params = $treeId !== null ? [$treeId] : [];

        $totalPersons = DB::select(
            "SELECT COUNT(*) AS cnt FROM genealogy_persons{$where}",
            $params
        )[0]->cnt ?? 0;

        if ($treeId !== null) {
            $totalEmbedded = DB::connection('pgsql_rag')->select(
                'SELECT COUNT(*) AS cnt FROM genealogy_person_embeddings WHERE tree_id = ?',
                [$treeId]
            )[0]->cnt ?? 0;
        } else {
            $totalEmbedded = DB::connection('pgsql_rag')->select(
                'SELECT COUNT(*) AS cnt FROM genealogy_person_embeddings'
            )[0]->cnt ?? 0;
        }

        $pending = max(0, $totalPersons - $totalEmbedded);

        // Per-tree breakdown (MySQL side)
        $treeCounts = DB::select(
            'SELECT tree_id, COUNT(*) AS cnt FROM genealogy_persons
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
                ['Total persons',     $totalPersons],
                ['Embeddings stored', $totalEmbedded],
                ['Pending (approx)',  $pending],
            ]
        );

        if (! empty($treeCounts)) {
            $this->newLine();
            $this->info('Per-tree breakdown:');
            $rows = [];
            foreach ($treeCounts as $row) {
                $tid = (int) $row->tree_id;
                $rows[] = [
                    'tree_id' => $tid,
                    'persons' => (int) $row->cnt,
                    'embedded' => $embMap[$tid] ?? 0,
                    'pending' => max(0, (int) $row->cnt - ($embMap[$tid] ?? 0)),
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
    private function fetchPersons(?int $treeId, int $limit, bool $reindex, bool $excludeLiving): array
    {
        $params = [];

        $sql = 'SELECT p.*
                FROM genealogy_persons p
                WHERE 1=1';

        if ($excludeLiving) {
            $sql .= ' AND (p.living IS NULL OR p.living = 0)';
        }

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
                $existingIds = array_map(fn ($row) => (int) $row->person_id, $existing);
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                $sql .= " AND p.id NOT IN ({$placeholders})";
                array_push($params, ...$existingIds);
            }
        }

        $sql .= ' ORDER BY p.id ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return DB::select($sql, $params);
    }

    /**
     * Delete existing embeddings (used with --reindex).
     */
    private function deleteExisting(array $personIds): int
    {
        $personIds = array_values(array_unique(array_filter(array_map(
            static fn ($personId): int => (int) $personId,
            $personIds
        ))));

        if ($personIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));

        return DB::connection('pgsql_rag')->delete(
            "DELETE FROM genealogy_person_embeddings WHERE person_id IN ({$placeholders})",
            $personIds
        );
    }

    /**
     * PostgreSQL person embeddings have no cross-DB foreign key to MySQL people.
     * Prune deleted-person rows after runs so stats and semantic search stay honest.
     */
    private function syncEmbeddingCoverage(?int $treeId): void
    {
        $personIds = $this->currentPersonIds($treeId);
        $pgsql = DB::connection('pgsql_rag');

        if ($personIds === []) {
            if ($treeId !== null) {
                $pgsql->delete('DELETE FROM genealogy_person_embeddings WHERE tree_id = ?', [$treeId]);
            } else {
                $pgsql->delete('DELETE FROM genealogy_person_embeddings');
            }

            return;
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $sql = "DELETE FROM genealogy_person_embeddings WHERE person_id NOT IN ({$placeholders})";
        $params = $personIds;

        if ($treeId !== null) {
            $sql .= ' AND tree_id = ?';
            $params[] = $treeId;
        }

        $pgsql->delete($sql, $params);
    }

    private function currentPersonIds(?int $treeId): array
    {
        $sql = 'SELECT id FROM genealogy_persons';
        $params = [];

        if ($treeId !== null) {
            $sql .= ' WHERE tree_id = ?';
            $params[] = $treeId;
        }

        return array_map(
            static fn (object $row): int => (int) $row->id,
            DB::select($sql, $params)
        );
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

        $name = trim(($person->given_name ?? '').' '.($person->surname ?? ''));
        if ($name !== '') {
            $parts[] = $name;
        }

        $sexLabel = match (strtoupper((string) ($person->sex ?? ''))) {
            'M' => 'male',
            'F' => 'female',
            default => null,
        };
        if ($sexLabel !== null) {
            $parts[] = $sexLabel;
        }

        foreach ($this->personFieldLabels() as $field => $label) {
            $value = $this->normalizePersonValue($person->{$field} ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = "{$label}: {$value}";
        }

        return implode(', ', $parts).'.';
    }

    /**
     * Build biography = search_text + first 500 chars of notes (if present).
     */
    private function buildBiography(object $person, string $searchText): string
    {
        if (empty($person->notes) || str_contains($searchText, (string) $person->notes)) {
            return $searchText;
        }

        return $searchText."\n".trim($person->notes);
    }

    private function personFieldLabels(): array
    {
        return [
            'id' => 'Person database ID',
            'tree_id' => 'Tree ID',
            'gedcom_id' => 'GEDCOM ID',
            'uid' => 'UID',
            'title' => 'Title',
            'given_name' => 'Given name',
            'surname' => 'Surname',
            'suffix' => 'Suffix',
            'nickname' => 'Nickname or also known as',
            'sex' => 'Sex',
            'birth_date' => 'Birth date',
            'birth_place' => 'Birth place',
            'birth_lat' => 'Birth latitude',
            'birth_lon' => 'Birth longitude',
            'birth_place_id' => 'Birth place ID',
            'death_date' => 'Death date',
            'death_place' => 'Death place',
            'death_lat' => 'Death latitude',
            'death_lon' => 'Death longitude',
            'death_place_id' => 'Death place ID',
            'burial_date' => 'Burial date',
            'burial_place' => 'Burial place',
            'burial_lat' => 'Burial latitude',
            'burial_lon' => 'Burial longitude',
            'burial_place_id' => 'Burial place ID',
            'occupation' => 'Occupation',
            'education' => 'Education',
            'religion' => 'Religion',
            'primary_photo_id' => 'Primary photo media ID',
            'notes' => 'Notes',
            'primary_language' => 'Primary language',
            'living' => 'Living status',
            'privacy_override' => 'Privacy override',
            'physical_description' => 'Physical description',
            'nationality' => 'Nationality',
            'ssn' => 'SSN',
            'id_number' => 'ID number',
            'property' => 'Property',
            'cause_of_death' => 'Cause of death',
            'created_at' => 'Record created at',
            'updated_at' => 'Record updated at',
            'rag_indexed_at' => 'RAG indexed at before this run',
        ];
    }

    private function normalizePersonValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $value;
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
