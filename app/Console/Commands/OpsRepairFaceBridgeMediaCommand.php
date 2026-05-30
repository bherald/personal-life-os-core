<?php

namespace App\Console\Commands;

use App\Services\Genealogy\FaceLinkBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class OpsRepairFaceBridgeMediaCommand extends Command
{
    protected $signature = 'ops:repair-face-bridge-media
        {--apply : Apply repairs; default is dry-run}
        {--limit=200 : Maximum candidate faces to inspect}
        {--tree= : Restrict to one genealogy tree id}
        {--include-deleted : Include non-active file_registry rows}
        {--json : Emit JSON output}';

    protected $description = 'Dry-run or repair active linked face rows missing genealogy_media/person_media bridge rows';

    public function handle(FaceLinkBridgeService $bridge): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $treeId = $this->positiveIntOption('tree');
        $includeDeleted = (bool) $this->option('include-deleted');

        $candidates = $this->missingMediaCandidates($limit, $treeId, $includeDeleted);
        $payload = $this->basePayload($candidates, $apply, $limit, $treeId, $includeDeleted);

        if (! $apply) {
            $payload['next_action'] = 'Run with --apply after reviewing candidates.';
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $processed = 0;
        $linked = 0;
        $mediaLinked = 0;
        $failed = 0;
        $actions = [];
        $errors = [];

        foreach ($candidates as $candidate) {
            $processed++;

            try {
                $result = DB::transaction(
                    fn (): array => $bridge->syncFaceLink((int) $candidate->face_id, (int) $candidate->person_id)
                );

                if (! ($result['success'] ?? false)) {
                    $failed++;
                    $errors[] = [
                        'face_id' => (int) $candidate->face_id,
                        'error' => (string) ($result['error'] ?? 'unknown'),
                    ];

                    continue;
                }

                $linked++;
                if (($result['media_id'] ?? null) !== null) {
                    $mediaLinked++;
                }

                $action = (string) ($result['person_media_action'] ?? 'unknown');
                $actions[$action] = ($actions[$action] ?? 0) + 1;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'face_id' => (int) $candidate->face_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $payload['applied'] = true;
        $payload['processed'] = $processed;
        $payload['linked_faces'] = $linked;
        $payload['media_linked_faces'] = $mediaLinked;
        $payload['failed'] = $failed;
        $payload['person_media_actions'] = $actions;
        $payload['errors'] = array_slice($errors, 0, 10);
        $payload['next_action'] = $failed > 0
            ? 'Review errors and rerun with a smaller --limit if needed.'
            : 'Run ops:face-telemetry-report to confirm bridge alignment.';

        $this->renderPayload($payload);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, object>
     */
    private function missingMediaCandidates(int $limit, ?int $treeId, bool $includeDeleted): array
    {
        $filters = [
            'frf.hidden = 0',
            'frf.genealogy_person_id IS NOT NULL',
            'gm.id IS NULL',
        ];
        $params = [];

        if (! $includeDeleted) {
            $filters[] = "fr.status = 'active'";
        }

        if ($treeId !== null) {
            $filters[] = 'gp.tree_id = ?';
            $params[] = $treeId;
        }

        $params[] = $limit;

        return DB::select(
            'SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.genealogy_person_id AS person_id,
                gp.tree_id,
                TRIM(CONCAT(COALESCE(gp.given_name, ""), " ", COALESCE(gp.surname, ""))) AS person_name,
                fr.filename,
                fr.status AS file_status,
                COALESCE(NULLIF(fr.current_path, ""), fr.original_path) AS registry_path,
                fr.original_path,
                fr.file_size
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             LEFT JOIN genealogy_media gm
               ON gm.tree_id = gp.tree_id
              AND (
                gm.nextcloud_path = COALESCE(NULLIF(fr.current_path, ""), fr.original_path)
                OR gm.original_path = fr.original_path
              )
             WHERE '.implode(' AND ', $filters).'
             ORDER BY frf.updated_at DESC, frf.id DESC
             LIMIT ?',
            $params
        );
    }

    /**
     * @param  array<int, object>  $candidates
     * @return array<string, mixed>
     */
    private function basePayload(array $candidates, bool $apply, int $limit, ?int $treeId, bool $includeDeleted): array
    {
        $files = [];
        $persons = [];
        $statusCounts = [];

        foreach ($candidates as $candidate) {
            $files[(int) $candidate->file_registry_id] = true;
            $persons[(int) $candidate->person_id] = true;
            $status = (string) ($candidate->file_status ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        return [
            'command' => 'ops:repair-face-bridge-media',
            'dry_run' => ! $apply,
            'applied' => false,
            'limit' => $limit,
            'tree_id' => $treeId,
            'include_deleted' => $includeDeleted,
            'candidate_faces' => count($candidates),
            'candidate_files' => count($files),
            'candidate_persons' => count($persons),
            'file_status_counts' => $statusCounts,
            'samples' => array_map(
                fn (object $candidate): array => [
                    'face_id' => (int) $candidate->face_id,
                    'file_registry_id' => (int) $candidate->file_registry_id,
                    'person_id' => (int) $candidate->person_id,
                    'tree_id' => (int) $candidate->tree_id,
                    'person_name' => (string) ($candidate->person_name ?? ''),
                    'filename' => (string) ($candidate->filename ?? ''),
                    'file_status' => (string) ($candidate->file_status ?? ''),
                    'has_registry_path' => trim((string) ($candidate->registry_path ?? '')) !== '',
                    'registry_path_hash' => trim((string) ($candidate->registry_path ?? '')) !== ''
                        ? hash('sha256', (string) $candidate->registry_path)
                        : null,
                ],
                array_slice($candidates, 0, 10)
            ),
        ];
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            '%s: faces=%d files=%d persons=%d apply=%s include_deleted=%s',
            $payload['command'],
            $payload['candidate_faces'],
            $payload['candidate_files'],
            $payload['candidate_persons'],
            $payload['dry_run'] ? 'no' : 'yes',
            $payload['include_deleted'] ? 'yes' : 'no'
        ));

        if (($payload['samples'] ?? []) !== []) {
            $this->table(
                ['face_id', 'file_registry_id', 'person_id', 'tree_id', 'filename', 'file_status'],
                array_map(
                    fn (array $sample): array => [
                        $sample['face_id'],
                        $sample['file_registry_id'],
                        $sample['person_id'],
                        $sample['tree_id'],
                        $sample['filename'],
                        $sample['file_status'],
                    ],
                    $payload['samples']
                )
            );
        }

        if (array_key_exists('processed', $payload)) {
            $this->line(sprintf(
                'processed=%d linked=%d media_linked=%d failed=%d',
                $payload['processed'],
                $payload['linked_faces'],
                $payload['media_linked_faces'],
                $payload['failed']
            ));
        }

        $this->line((string) ($payload['next_action'] ?? ''));
    }
}
