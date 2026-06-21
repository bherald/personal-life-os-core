<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpsFaceProvenanceQuarantineCommand extends Command
{
    protected $signature = 'ops:face-provenance-quarantine
        {--tree=4 : Genealogy tree id}
        {--name= : Face label to quarantine}
        {--person-id=* : Genealogy person id to include}
        {--status=plos_only_untrusted,export_exact_person_conflict : Comma-separated provenance statuses}
        {--limit=5000 : Maximum rows to change}
        {--confirm : Apply changes}
        {--json : Emit machine-readable JSON}
        {--compact : Omit row id samples from JSON output}';

    protected $description = 'Clear untrusted materialized face identity claims without deleting photo files';

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $name = trim((string) $this->option('name'));
        $personIds = array_values(array_filter(array_map('intval', (array) $this->option('person-id')), fn (int $id): bool => $id > 0));
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('status')))));
        $limit = max(1, min(20000, (int) $this->option('limit')));
        $confirm = (bool) $this->option('confirm');
        $batchId = (string) Str::uuid();

        if ($name === '' && $personIds === []) {
            return $this->failPayload('name_or_person_id_required');
        }

        if ($statuses === []) {
            return $this->failPayload('status_required');
        }

        [$where, $params] = $this->targetWhere($treeId, $name, $personIds, $statuses);
        $params[] = $limit;
        $rows = DB::select(
            "SELECT fip.file_registry_face_id, fip.file_registry_id, fip.genealogy_person_id, fip.status,
                    frf.person_name, frf.verified, frf.cluster_id
             FROM face_identity_provenance fip
             JOIN file_registry_faces frf ON frf.id = fip.file_registry_face_id
             WHERE {$where}
             ORDER BY fip.file_registry_face_id ASC
             LIMIT ?",
            $params
        );

        $payload = [
            'command' => 'ops:face-provenance-quarantine',
            'mode' => $confirm ? 'write' : 'dry_run',
            'read_only' => ! $confirm,
            'mutation_allowed' => $confirm,
            'batch_id' => $batchId,
            'filters' => [
                'tree_id' => $treeId,
                'name' => $name !== '' ? $name : null,
                'person_ids' => $personIds,
                'statuses' => $statuses,
                'limit' => $limit,
            ],
            'summary' => [
                'matched_rows' => count($rows),
                'changed_rows' => 0,
                'provenance_rows_cleared' => 0,
                'photo_files_deleted' => 0,
                'auto_assignment_performed' => false,
            ],
            'samples' => array_slice(array_map(fn (object $row): array => [
                'face_id' => (int) $row->file_registry_face_id,
                'file_registry_id' => (int) $row->file_registry_id,
                'person_name' => (string) ($row->person_name ?? ''),
                'person_id' => $row->genealogy_person_id !== null ? (int) $row->genealogy_person_id : null,
                'status' => (string) $row->status,
            ], $rows), 0, 25),
        ];

        if ($confirm && $rows !== []) {
            DB::transaction(function () use ($rows, $treeId, $batchId, &$payload): void {
                $ids = array_map(fn (object $row): int => (int) $row->file_registry_face_id, $rows);
                DB::insert(
                    "INSERT INTO genealogy_change_history
                        (tree_id, entity_type, entity_id, action, field_name, old_value, new_value,
                         old_data, new_data, changed_at, change_reason, batch_id)
                     VALUES (?, 'file_registry_faces', 0, 'update', 'face_identity_provenance_quarantine',
                             ?, 'identity claims cleared; photos retained',
                             ?, JSON_OBJECT('person_name', '', 'face_index', 'quarantine_negative_face_id', 'genealogy_person_id', NULL, 'verified', 0, 'cluster_id', NULL),
                             NOW(), ?, ?)",
                    [
                        $treeId,
                        'face rows before quarantine: '.count($rows),
                        json_encode([
                            'count' => count($rows),
                            'ids' => $ids,
                            'operator_decision' => 'untrusted face identity drift',
                        ], JSON_UNESCAPED_SLASHES),
                        'Face provenance quarantine from operator-confirmed drift',
                        $batchId,
                    ]
                );

                foreach (array_chunk($ids, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $payload['summary']['changed_rows'] += DB::update(
                        "UPDATE file_registry_faces
                         SET face_index = -id,
                             person_name = '',
                             genealogy_person_id = NULL,
                             verified = 0,
                             cluster_id = NULL,
                             updated_at = NOW()
                         WHERE id IN ({$placeholders})",
                        $chunk
                    );
                    $payload['summary']['provenance_rows_cleared'] += DB::delete(
                        "DELETE FROM face_identity_provenance
                         WHERE file_registry_face_id IN ({$placeholders})",
                        $chunk
                    );
                }
            });
        }

        if ((bool) $this->option('compact')) {
            unset($payload['samples']);
            $payload['compact'] = true;
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function targetWhere(int $treeId, string $name, array $personIds, array $statuses): array
    {
        $where = ['fip.tree_id = ?'];
        $params = [$treeId];

        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "fip.status IN ({$statusPlaceholders})";
        array_push($params, ...$statuses);

        $identity = [];
        if ($name !== '') {
            $identity[] = 'LOWER(TRIM(frf.person_name)) = ?';
            $params[] = strtolower($name);
        }
        if ($personIds !== []) {
            $personPlaceholders = implode(',', array_fill(0, count($personIds), '?'));
            $identity[] = "frf.genealogy_person_id IN ({$personPlaceholders})";
            array_push($params, ...$personIds);
        }
        $where[] = '('.implode(' OR ', $identity).')';

        return [implode(' AND ', $where), $params];
    }

    private function failPayload(string $error): int
    {
        $this->renderPayload([
            'command' => 'ops:face-provenance-quarantine',
            'mode' => 'error',
            'error' => $error,
        ]);

        return self::FAILURE;
    }

    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-provenance-quarantine: mode=%s matched=%d changed=%d',
            $payload['mode'] ?? 'unknown',
            (int) ($payload['summary']['matched_rows'] ?? 0),
            (int) ($payload['summary']['changed_rows'] ?? 0)
        ));
    }
}
