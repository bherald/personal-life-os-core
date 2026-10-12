<?php

namespace App\Console\Commands;

use App\Services\Genealogy\FaceCandidateDecisionService;
use App\Services\Genealogy\FaceCandidateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsFaceNamedOnlyTriageCommand extends Command
{
    protected $signature = 'ops:face-named-only-triage
        {--apply : Apply safe terminal review decisions; default is dry-run}
        {--tree= : Genealogy tree id; defaults only when exactly one tree exists}
        {--limit=500 : Maximum named-only faces to inspect}
        {--offset=0 : Offset into named-only face query}
        {--cluster= : Restrict to one face cluster id}
        {--include-inactive : Include non-active file_registry rows}
        {--all-ages : Include fresh rows; default is rows older than --hours}
        {--hours=24 : Stale threshold when --all-ages is not set}
        {--compact : Omit samples from JSON output}
        {--json : Emit JSON output}';

    protected $description = 'Dry-run or apply safe non-identity named-only face triage decisions';

    public function handle(FaceCandidateService $candidates, FaceCandidateDecisionService $decisions): int
    {
        $tree = $this->resolveTreeId($this->positiveIntOption('tree'));
        if ($tree['id'] <= 0) {
            $payload = $this->basePayload(false, 0, 0, null, null, true);
            $payload['success'] = false;
            $payload['error'] = 'tree_id_required';
            $payload['tree_count'] = $tree['count'];
            $this->renderPayload($payload);

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $offset = max(0, (int) $this->option('offset'));
        $clusterId = $this->positiveIntOption('cluster');
        $includeInactive = (bool) $this->option('include-inactive');
        $allAges = (bool) $this->option('all-ages');
        $hours = max(1, (int) $this->option('hours'));

        $rows = $this->namedOnlyRows($tree['id'], $limit, $offset, $clusterId, $includeInactive, $allAges, $hours);
        $payload = $this->basePayload($apply, $limit, $offset, $tree['id'], $clusterId, false);
        $payload['filters']['active_only'] = ! $includeInactive;
        $payload['filters']['stale_only'] = ! $allAges;
        $payload['filters']['stale_threshold_hours'] = $allAges ? null : $hours;
        $payload['summary']['scanned_faces'] = count($rows);

        foreach ($rows as $row) {
            $faceId = (int) $row->face_id;
            $projection = $candidates->candidatesForFace($faceId, $tree['id'], 10, false);
            $state = (string) ($projection['candidate_state'] ?? ('error:'.($projection['error'] ?? 'unknown')));
            $suggested = (string) ($projection['suggested_action'] ?? 'none');
            $action = $this->safeTerminalAction($projection);
            $hasMedia = isset($row->genealogy_media_id) && (int) $row->genealogy_media_id > 0;

            $payload['summary']['candidate_states'][$state] = ($payload['summary']['candidate_states'][$state] ?? 0) + 1;
            $payload['summary']['suggested_actions'][$suggested] = ($payload['summary']['suggested_actions'][$suggested] ?? 0) + 1;

            if ($action === null) {
                $this->sample($payload, 'review_required', $row, $state, null, $hasMedia, $suggested);

                continue;
            }

            $payload['summary']['safe_terminal_candidates']++;
            $payload['summary']['safe_terminal_actions'][$action] = ($payload['summary']['safe_terminal_actions'][$action] ?? 0) + 1;

            if (! $hasMedia) {
                $payload['summary']['face_only_terminal_candidates']++;
            }

            $payload['summary']['applicable_terminal_candidates']++;

            if (! $apply) {
                $this->sample($payload, 'would_apply', $row, $state, $action, true, $suggested);

                continue;
            }

            $result = $decisions->decide($faceId, [
                'action' => $action,
                'tree_id' => $tree['id'],
                'reason' => $this->decisionReason($action),
            ], null);

            if (($result['success'] ?? false) === true) {
                $payload['summary']['applied_decisions']++;
                $this->sample($payload, 'applied', $row, $state, $action, true, $suggested, $result['queue'] ?? null);

                continue;
            }

            $error = (string) ($result['error'] ?? 'unknown');
            $payload['summary']['errors'][$error] = ($payload['summary']['errors'][$error] ?? 0) + 1;
            $this->sample($payload, 'error:'.$error, $row, $state, $action, true, $suggested);
        }

        if ((bool) $this->option('compact')) {
            $payload['samples'] = [];
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<int, object>
     */
    private function namedOnlyRows(int $treeId, int $limit, int $offset, ?int $clusterId, bool $includeInactive, bool $allAges, int $hours): array
    {
        $filters = [
            'frf.hidden = 0',
            'frf.genealogy_person_id IS NULL',
            "TRIM(COALESCE(frf.person_name, '')) <> ''",
            "NOT EXISTS (
                SELECT 1
                FROM genealogy_face_match_queue q
                WHERE q.file_registry_face_id = frf.id
                  AND q.status = 'ignored'
                  AND JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal') = true
            )",
        ];
        $params = [$treeId];

        if (! $includeInactive) {
            $filters[] = "fr.status = 'active'";
        }

        if (! $allAges) {
            $filters[] = 'frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)';
            $params[] = $hours;
        }

        if ($clusterId !== null) {
            $filters[] = 'frf.cluster_id = ?';
            $params[] = $clusterId;
        }

        $params[] = $limit;
        $params[] = $offset;

        return DB::select(
            'SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.person_name,
                frf.cluster_id,
                frf.confidence,
                frf.verified,
                frf.updated_at,
                fr.filename,
                fr.status AS file_status,
                fr.date_taken,
                MAX(gm.id) AS genealogy_media_id
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             LEFT JOIN genealogy_media gm
               ON gm.tree_id = ?
              AND (
                gm.nextcloud_path = COALESCE(NULLIF(fr.current_path, \'\'), fr.original_path)
                OR gm.original_path = fr.original_path
              )
             WHERE '.implode(' AND ', $filters).'
             GROUP BY frf.id, frf.file_registry_id, frf.person_name, frf.cluster_id, frf.confidence, frf.verified, frf.updated_at, fr.filename, fr.status, fr.date_taken
             ORDER BY frf.updated_at ASC, frf.id ASC
             LIMIT ? OFFSET ?',
            $params
        );
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function safeTerminalAction(array $projection): ?string
    {
        if (($projection['success'] ?? false) !== true) {
            return null;
        }

        return match ((string) ($projection['candidate_state'] ?? '')) {
            'vague_name' => 'too_vague',
            'no_candidate' => 'outside_tree',
            default => null,
        };
    }

    private function decisionReason(string $action): string
    {
        return $action === 'outside_tree'
            ? 'RTC face named-only cleanup: candidate service returned no FT4 person candidates; non-identity outside-tree decision.'
            : 'RTC face named-only cleanup: vague/non-person face label; non-identity too-vague decision.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $queue
     */
    private function sample(array &$payload, string $bucket, object $row, string $state, ?string $action, bool $hasMedia, string $suggested, ?array $queue = null): void
    {
        if (count($payload['samples']) >= 25) {
            return;
        }

        $payload['samples'][] = [
            'bucket' => $bucket,
            'face_id' => (int) $row->face_id,
            'file_registry_id' => (int) $row->file_registry_id,
            'cluster_id' => $row->cluster_id !== null ? (int) $row->cluster_id : null,
            'person_name' => (string) ($row->person_name ?? ''),
            'filename' => (string) ($row->filename ?? ''),
            'candidate_state' => $state,
            'suggested_action' => $suggested,
            'safe_terminal_action' => $action,
            'has_genealogy_media' => $hasMedia,
            'genealogy_media_id' => $hasMedia ? (int) $row->genealogy_media_id : null,
            'queue' => $queue,
        ];
    }

    /**
     * @return array{id:int,count:int|null}
     */
    private function resolveTreeId(?int $treeId): array
    {
        if ($treeId !== null && $treeId > 0) {
            return ['id' => $treeId, 'count' => null];
        }

        $tree = DB::table('genealogy_trees')
            ->selectRaw('COUNT(*) AS tree_count, MIN(id) AS only_tree_id')
            ->first();

        $count = (int) ($tree->tree_count ?? 0);
        if ($count === 1) {
            return ['id' => (int) $tree->only_tree_id, 'count' => $count];
        }

        return ['id' => 0, 'count' => $count];
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
     * @return array<string, mixed>
     */
    private function basePayload(bool $apply, int $limit, int $offset, ?int $treeId, ?int $clusterId, bool $blocked): array
    {
        return [
            'command' => 'ops:face-named-only-triage',
            'mode' => $apply ? 'apply' : 'dry_run',
            'dry_run' => ! $apply,
            'read_only' => ! $apply,
            'mutation_allowed' => $apply,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'tree_id' => $treeId,
                'limit' => $limit,
                'offset' => $offset,
                'cluster_id' => $clusterId,
            ],
            'posture' => [
                'schema' => 'face_named_only_triage.ds.v1',
                'purpose' => 'safe_non_identity_named_only_review_decisions',
                'operator_review_required' => true,
                'automation_allowed' => false,
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'cluster_split_allowed' => false,
                'create_person_allowed' => false,
                'writeback_allowed' => false,
                'canonical_genealogy_write_allowed' => false,
                'allowed_apply_actions' => ['outside_tree', 'too_vague'],
            ],
            'summary' => [
                'query_would_run' => ! $blocked,
                'scanned_faces' => 0,
                'candidate_states' => [],
                'suggested_actions' => [],
                'safe_terminal_candidates' => 0,
                'safe_terminal_actions' => [],
                'applicable_terminal_candidates' => 0,
                'face_only_terminal_candidates' => 0,
                'blocked_missing_genealogy_media' => 0,
                'applied_decisions' => 0,
                'errors' => [],
            ],
            'samples' => [],
        ];
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

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'face-named-only-triage: scanned=%d safe=%d applicable=%d blocked_media=%d applied=%d',
            (int) ($summary['scanned_faces'] ?? 0),
            (int) ($summary['safe_terminal_candidates'] ?? 0),
            (int) ($summary['applicable_terminal_candidates'] ?? 0),
            (int) ($summary['blocked_missing_genealogy_media'] ?? 0),
            (int) ($summary['applied_decisions'] ?? 0)
        ));
    }
}
