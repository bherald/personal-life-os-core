<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FaceOperatorRemovalRepairCommand extends Command
{
    protected $signature = 'faces:repair-operator-removals
                            {--apply : Legacy option; identity removals require individual review}
                            {--limit=5000 : Maximum face rows to repair}
                            {--person-id= : Restrict to one genealogy person id}
                            {--tree-id= : Restrict findings to one family tree}
                            {--json : Emit JSON output}
                            {--compact : Emit compact JSON output}';

    protected $description = 'Report possible stale face links for individual operator review without changing identity';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, min((int) $this->option('limit'), 50000));
        $personId = $this->positiveInt($this->option('person-id'));
        $treeId = $this->positiveInt($this->option('tree-id'));
        foreach (['person-id' => $personId, 'tree-id' => $treeId] as $option => $id) {
            if ($this->option($option) !== null && $this->option($option) !== '' && $id === null) {
                $this->error("--{$option} requires a positive integer.");

                return self::FAILURE;
            }
        }

        $targets = $this->targetRows($limit, $personId, $treeId);
        $summary = $this->summarize($targets);

        $result = [
            'schema' => 'face_operator_removal_repair.v2',
            'mode' => $apply && $targets !== [] ? 'review_required' : 'dry_run',
            'read_only' => true,
            'mutation_allowed' => false,
            'filters' => [
                'person_id' => $personId,
                'tree_id' => $treeId,
                'limit' => $limit,
            ],
            'target_count' => count($targets),
            'held_count' => count($targets),
            'review_required' => $targets !== [],
            'review_reason' => 'A blank legacy label or rejected suggestion does not authorize unlinking a current identity. Review the individual face and current decision history before removal.',
            'target_summary' => $summary,
            'applied' => [
                'face_rows_cleared' => 0,
                'metadata_files_marked_pending' => 0,
                'scan_logs_reset' => 0,
            ],
            'sample' => array_slice($targets, 0, 20),
        ];

        // Suggestion rejection explicitly leaves existing identity links unchanged.
        // Old labels and rejection history cannot supply current unlink authority.
        $exit = $apply && $targets !== [] ? self::FAILURE : self::SUCCESS;

        if ($this->option('json') || $this->option('compact')) {
            $flags = $this->option('compact') ? 0 : JSON_PRETTY_PRINT;
            $this->line(json_encode($result, $flags | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->info('Face operator removal repair');
        $this->line('Mode: '.$result['mode']);
        $this->line('Targets: '.count($targets));
        foreach ($summary as $row) {
            $this->line(sprintf(
                '  %s: faces=%d files=%d people=%d',
                $row['bucket'],
                $row['face_rows'],
                $row['files'],
                $row['people']
            ));
        }
        if ($apply) {
            $this->line('Cleared face rows: '.$result['applied']['face_rows_cleared']);
            $this->line('Files marked pending: '.$result['applied']['metadata_files_marked_pending']);
            $this->line('Scan logs reset: '.$result['applied']['scan_logs_reset']);
            if ($targets !== []) {
                $this->warn($result['review_reason']);
            }
        }

        return $exit;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function targetRows(int $limit, ?int $personId, ?int $treeId): array
    {
        $personFilter = $personId !== null ? 'AND frf.genealogy_person_id = ?' : '';
        $treeFilter = $treeId !== null ? 'AND gp.tree_id = ?' : '';
        $params = [...($personId !== null ? [$personId] : []), ...($treeId !== null ? [$treeId] : []), $limit];

        $rows = DB::select("
            SELECT
                frf.id,
                frf.file_registry_id,
                frf.genealogy_person_id,
                frf.person_name,
                fr.current_path,
                CONCAT_WS(' ', gp.given_name, gp.surname) AS genealogy_name,
                GROUP_CONCAT(DISTINCT targets.bucket ORDER BY targets.bucket SEPARATOR ',') AS buckets
            FROM (
                SELECT frf_blank.id, 'blank_linked' AS bucket
                FROM file_registry_faces frf_blank
                WHERE frf_blank.genealogy_person_id IS NOT NULL
                  AND NULLIF(TRIM(COALESCE(frf_blank.person_name, '')), '') IS NULL

                UNION

                SELECT frf_rejected.id, 'rejected_queue_linked' AS bucket
                FROM genealogy_face_match_queue q
                JOIN file_registry_faces frf_rejected ON frf_rejected.id = q.file_registry_face_id
                JOIN genealogy_persons rejected_person ON rejected_person.id = frf_rejected.genealogy_person_id AND rejected_person.tree_id = q.tree_id
                WHERE q.status = 'rejected'
                  AND q.suggested_person_id IS NOT NULL
                  AND frf_rejected.genealogy_person_id = q.suggested_person_id

                UNION

                SELECT frf_not_person.id, 'not_this_person_linked' AS bucket
                FROM genealogy_face_match_queue q
                JOIN file_registry_faces frf_not_person ON frf_not_person.id = q.file_registry_face_id
                JOIN genealogy_persons rejected_person ON rejected_person.id = frf_not_person.genealogy_person_id AND rejected_person.tree_id = q.tree_id
                WHERE JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) = 'not_this_person'
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.genealogy_person_id')) AS UNSIGNED) = frf_not_person.genealogy_person_id
            ) targets
            JOIN file_registry_faces frf ON frf.id = targets.id
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE frf.genealogy_person_id IS NOT NULL
              {$personFilter}
              {$treeFilter}
            GROUP BY frf.id, frf.file_registry_id, frf.genealogy_person_id, frf.person_name, fr.current_path, gp.given_name, gp.surname
            ORDER BY frf.id ASC
            LIMIT ?
        ", $params);

        return array_map(static fn (object $row): array => [
            'clear_person_name' => false,
            'review_required' => true,
            'face_id' => (int) $row->id,
            'file_registry_id' => (int) $row->file_registry_id,
            'genealogy_person_id' => (int) $row->genealogy_person_id,
            'person_name' => $row->person_name,
            'genealogy_name' => $row->genealogy_name,
            'current_path' => $row->current_path,
            'buckets' => array_values(array_filter(explode(',', (string) $row->buckets))),
        ], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     * @return array<int, array{bucket:string,face_rows:int,files:int,people:int}>
     */
    private function summarize(array $targets): array
    {
        $summary = [];
        foreach ($targets as $target) {
            foreach ($target['buckets'] as $bucket) {
                $summary[$bucket] ??= [
                    'bucket' => $bucket,
                    'face_ids' => [],
                    'file_ids' => [],
                    'person_ids' => [],
                ];
                $summary[$bucket]['face_ids'][(int) $target['face_id']] = true;
                $summary[$bucket]['file_ids'][(int) $target['file_registry_id']] = true;
                $summary[$bucket]['person_ids'][(int) $target['genealogy_person_id']] = true;
            }
        }

        return array_values(array_map(static fn (array $row): array => [
            'bucket' => $row['bucket'],
            'face_rows' => count($row['face_ids']),
            'files' => count($row['file_ids']),
            'people' => count($row['person_ids']),
        ], $summary));
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }
}
