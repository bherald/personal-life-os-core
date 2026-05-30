<?php

namespace App\Console\Commands;

use App\Services\Genealogy\DuplicateDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyDuplicateScanCommand extends Command
{
    protected $signature = 'genealogy:duplicate-scan
        {--tree= : Tree ID to scan}
        {--all-trees : Scan every known genealogy tree}
        {--min-score=0.75 : Minimum duplicate score to store}
        {--limit=200 : Maximum candidates per tree}
        {--dry-run : Preview candidates without writing pending duplicate rows}
        {--json : Emit machine-readable JSON}
        {--compact : With --json, emit aggregate-only scheduled-output JSON without per-tree rows}';

    protected $description = 'Scan genealogy people for duplicate candidates and queue pending review rows without merging records';

    public function handle(DuplicateDetectionService $duplicates): int
    {
        foreach (['genealogy_persons', 'genealogy_duplicate_pairs'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Required genealogy table is missing: {$table}");

                return self::FAILURE;
            }
        }

        $treeIds = $this->treeIds();
        if ($treeIds === []) {
            $this->error('No genealogy tree IDs found to scan.');

            return self::FAILURE;
        }

        $minScore = max(0.0, min(1.0, (float) $this->option('min-score')));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');

        $results = [];
        foreach ($treeIds as $treeId) {
            $candidates = $duplicates->findDuplicatePersons($treeId, [
                'minScore' => $minScore,
                'limit' => $limit,
                'includeResolved' => false,
            ]);

            $stored = 0;
            $updated = 0;
            $skippedResolved = 0;

            if (! $dryRun) {
                foreach ($candidates as $candidate) {
                    $result = $this->storeCandidate($treeId, $candidate);
                    $stored += $result === 'created' ? 1 : 0;
                    $updated += $result === 'updated' ? 1 : 0;
                    $skippedResolved += $result === 'skipped_resolved' ? 1 : 0;
                }
            }

            $results[] = [
                'tree_id' => $treeId,
                'candidate_count' => count($candidates),
                'created' => $stored,
                'updated' => $updated,
                'skipped_resolved' => $skippedResolved,
            ];
        }

        $payload = [
            'command' => 'genealogy:duplicate-scan',
            'mode' => $dryRun ? 'dry_run' : 'review_queue_update',
            'mutation_allowed' => ! $dryRun,
            'canonical_record_mutation_allowed' => false,
            'min_score' => $minScore,
            'limit' => $limit,
            'tree_count' => count($treeIds),
            'results' => $results,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->option('json')) {
            if ((bool) $this->option('compact')) {
                $payload = $this->compactPayload($payload);
            }

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Tree', 'Candidates', 'Created', 'Updated', 'Skipped Resolved'],
            array_map(static fn (array $row): array => [
                $row['tree_id'],
                $row['candidate_count'],
                $row['created'],
                $row['updated'],
                $row['skipped_resolved'],
            ], $results)
        );
        $this->line('[ITEMS_PROCESSED:'.array_sum(array_column($results, 'candidate_count')).']');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $results = array_values(array_filter(
            is_array($payload['results'] ?? null) ? $payload['results'] : [],
            static fn ($result): bool => is_array($result)
        ));

        return [
            'command' => 'genealogy:duplicate-scan',
            'compact' => true,
            'mode' => (string) ($payload['mode'] ?? 'unknown'),
            'mutation_allowed' => (bool) ($payload['mutation_allowed'] ?? false),
            'canonical_record_mutation_allowed' => false,
            'min_score' => (float) ($payload['min_score'] ?? 0),
            'limit' => (int) ($payload['limit'] ?? 0),
            'tree_count' => (int) ($payload['tree_count'] ?? count($results)),
            'summary' => [
                'candidate_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['candidate_count'] ?? 0), $results)),
                'created' => array_sum(array_map(static fn (array $row): int => (int) ($row['created'] ?? 0), $results)),
                'updated' => array_sum(array_map(static fn (array $row): int => (int) ($row['updated'] ?? 0), $results)),
                'skipped_resolved' => array_sum(array_map(static fn (array $row): int => (int) ($row['skipped_resolved'] ?? 0), $results)),
            ],
            'posture' => [
                'aggregate_only' => true,
                'per_tree_rows_included' => false,
                'tree_ids_included' => false,
                'person_ids_included' => false,
                'person_names_included' => false,
                'candidate_rows_included' => false,
                'canonical_record_mutation_allowed' => false,
            ],
            'timestamp' => $payload['timestamp'] ?? now()->toIso8601String(),
        ];
    }

    /**
     * @return list<int>
     */
    private function treeIds(): array
    {
        if ($this->option('tree') !== null && $this->option('tree') !== '') {
            $treeId = (int) $this->option('tree');

            return $treeId > 0 ? [$treeId] : [];
        }

        if (! $this->option('all-trees') && ! Schema::hasTable('genealogy_trees')) {
            return [];
        }

        if (! Schema::hasTable('genealogy_trees')) {
            return [];
        }

        return DB::table('genealogy_trees')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private function storeCandidate(int $treeId, array $candidate): string
    {
        $person1Id = (int) data_get($candidate, 'person1.id');
        $person2Id = (int) data_get($candidate, 'person2.id');
        if ($person1Id <= 0 || $person2Id <= 0 || $person1Id === $person2Id) {
            return 'skipped_resolved';
        }

        $personA = min($person1Id, $person2Id);
        $personB = max($person1Id, $person2Id);
        $existing = DB::table('genealogy_duplicate_pairs')
            ->where('tree_id', $treeId)
            ->where('person1_id', $personA)
            ->where('person2_id', $personB)
            ->first(['id', 'status']);

        if ($existing && ! in_array((string) $existing->status, ['pending', 'pending_merge'], true)) {
            return 'skipped_resolved';
        }

        $values = [
            'score' => round((float) ($candidate['score'] ?? 0), 3),
            'notes' => $this->candidateNotes($candidate),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('genealogy_duplicate_pairs')
                ->where('id', $existing->id)
                ->update($values);

            return 'updated';
        }

        DB::table('genealogy_duplicate_pairs')->insert($values + [
            'tree_id' => $treeId,
            'person1_id' => $personA,
            'person2_id' => $personB,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        return 'created';
    }

    private function candidateNotes(array $candidate): string
    {
        $notes = [
            'source' => 'genealogy:duplicate-scan',
            'reasons' => array_values((array) ($candidate['reasons'] ?? [])),
            'person1_name' => data_get($candidate, 'person1.name'),
            'person2_name' => data_get($candidate, 'person2.name'),
        ];

        return (string) json_encode($notes, JSON_UNESCAPED_SLASHES);
    }
}
