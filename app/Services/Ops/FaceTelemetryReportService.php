<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Throwable;

class FaceTelemetryReportService
{
    private const STATUS_RANK = [
        'observe_ok' => 0,
        'observe_warning' => 1,
        'review_required' => 2,
    ];

    public function collect(int $hours = 24, bool $dryRun = false): array
    {
        $hours = max(1, min(720, $hours));

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'window_hours' => $hours,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'sections' => [],
            'threshold_breaches' => [],
            'recommendations' => [],
        ];

        if ($dryRun) {
            $payload['status'] = 'observe_ok';
            $payload['sections'] = [
                'dry_run' => [
                    'status' => 'observe_ok',
                    'note' => 'Dry run only; no database probes executed.',
                ],
            ];

            return $payload;
        }

        $payload['sections'] = [
            'mysql_face_registry' => $this->collectMysqlFaceRegistry($hours),
            'review_queue' => $this->collectReviewQueue($hours),
            'candidate_decisions' => $this->collectCandidateDecisions($hours),
            'bridge_alignment' => $this->collectBridgeAlignment(),
            'postgres_face_vectors' => $this->collectPostgresFaceVectors(),
            'face_jobs' => $this->collectFaceJobs($hours),
        ];

        [$breaches, $recommendations] = $this->evaluateThresholds($payload['sections']);
        $payload['threshold_breaches'] = $breaches;
        $payload['recommendations'] = $recommendations;
        $payload['status'] = $this->worstStatus(array_merge(
            array_column($payload['sections'], 'status'),
            array_column($breaches, 'status')
        ));

        return $payload;
    }

    public function toMarkdown(array $payload): string
    {
        $lines = [
            '# Face Telemetry Report',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Captured: `'.($payload['captured_at'] ?? 'unknown').'`',
            '- Window Hours: `'.($payload['window_hours'] ?? 'unknown').'`',
            '',
            '## Sections',
            '',
        ];

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $lines[] = '- `'.$name.'`: `'.($section['status'] ?? 'unknown').'`';
        }

        $registrySummary = $payload['sections']['mysql_face_registry']['summary'] ?? null;
        if (is_array($registrySummary)) {
            $lines[] = '';
            $lines[] = '## Face Registry';
            $lines[] = '';
            $lines[] = '- Total faces: `'.($registrySummary['total_faces'] ?? 0).'`';
            $lines[] = '- Visible faces: `'.($registrySummary['visible_faces'] ?? 0).'`';
            $lines[] = '- Genealogy-linked faces: `'.($registrySummary['genealogy_linked_faces'] ?? 0).'`';
            $lines[] = '- Named-only faces: `'.($registrySummary['named_only_faces'] ?? 0).'`';
            $lines[] = '- Stale named-only faces: `'.($registrySummary['stale_named_only_faces'] ?? 0).'`';
            $lines[] = '- Open named-only faces: `'.($registrySummary['open_named_only_faces'] ?? 0).'`';
            $lines[] = '- Stale open named-only faces: `'.($registrySummary['stale_open_named_only_faces'] ?? 0).'`';
            $lines[] = '- Terminal-decided named-only faces: `'.($registrySummary['terminal_decided_named_only_faces'] ?? 0).'`';
            $lines[] = '- Oldest named-only updated at: `'.($registrySummary['oldest_named_only_updated_at'] ?? 'none').'`';
            $lines[] = '- Newest named-only updated at: `'.($registrySummary['newest_named_only_updated_at'] ?? 'none').'`';
        }

        $candidateSummary = $payload['sections']['candidate_decisions']['summary'] ?? null;
        if (is_array($candidateSummary)) {
            $lines[] = '';
            $lines[] = '## Candidate Decisions';
            $lines[] = '';
            $lines[] = '- Decision rows: `'.($candidateSummary['decision_rows'] ?? 0).'`';
            $lines[] = '- Decided faces: `'.($candidateSummary['decided_faces'] ?? 0).'`';
            $lines[] = '- Recent decisions: `'.($candidateSummary['recent_decisions'] ?? 0).'`';
            $lines[] = '- Latest decision: `'.($candidateSummary['latest_decision_at'] ?? 'none').'`';
            $lines[] = '- Terminal decisions: `'.($candidateSummary['terminal_decisions'] ?? 0).'`';
            $lines[] = '- Action buckets: keep_name_only=`'.($candidateSummary['keep_name_only'] ?? 0).'`, outside_tree=`'.($candidateSummary['outside_tree'] ?? 0).'`, too_vague=`'.($candidateSummary['too_vague'] ?? 0).'`, not_this_person=`'.($candidateSummary['not_this_person'] ?? 0).'`, defer=`'.($candidateSummary['deferred'] ?? 0).'`';
        }

        $bridgeSummary = $payload['sections']['bridge_alignment']['summary'] ?? null;
        if (is_array($bridgeSummary)) {
            $lines[] = '';
            $lines[] = '## Bridge Alignment';
            $lines[] = '';
            $lines[] = '- Linked faces: `'.($bridgeSummary['linked_faces'] ?? 0).'`';
            $lines[] = '- Aligned faces: `'.($bridgeSummary['aligned_faces'] ?? 0).'`';
            $lines[] = '- Missing genealogy media links: `'.($bridgeSummary['missing_media_links'] ?? 0).'`';
            $lines[] = '- Missing person-media links: `'.($bridgeSummary['missing_person_media_links'] ?? 0).'`';

            $samples = $bridgeSummary['gap_samples'] ?? [];
            if (is_array($samples) && $samples !== []) {
                $lines[] = '';
                $lines[] = '### Bridge Gap Samples';
                $lines[] = '';
                foreach ($samples as $sample) {
                    if (! is_array($sample)) {
                        continue;
                    }

                    $lines[] = sprintf(
                        '- `%s`: face=`%s`, file=`%s`, person=`%s`, tree=`%s`, media=`%s`, path_present=`%s`',
                        $sample['gap_type'] ?? 'unknown',
                        $sample['face_id'] ?? 'unknown',
                        $sample['file_registry_id'] ?? 'unknown',
                        $sample['person_id'] ?? 'unknown',
                        $sample['tree_id'] ?? 'unknown',
                        $sample['genealogy_media_id'] ?? 'none',
                        ($sample['has_registry_path'] ?? false) ? 'yes' : 'no'
                    );
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Threshold Breaches';
        $lines[] = '';
        foreach (($payload['threshold_breaches'] ?? []) as $breach) {
            $lines[] = '- `'.($breach['status'] ?? 'observe_warning').'` '.$breach['message'];
        }
        if (($payload['threshold_breaches'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $lines[] = '';
        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $lines[] = '- '.$recommendation;
        }
        if (($payload['recommendations'] ?? []) === []) {
            $lines[] = '- No human action recommended from this observe-only sample.';
        }

        return implode("\n", $lines)."\n";
    }

    public function toCompactPayload(array $payload): array
    {
        $sections = $payload['sections'] ?? [];
        $registry = $this->summary($sections, 'mysql_face_registry');
        $queue = $this->summary($sections, 'review_queue');
        $decisions = $this->summary($sections, 'candidate_decisions');
        $bridge = $this->summary($sections, 'bridge_alignment');
        $vectors = $this->summary($sections, 'postgres_face_vectors');
        $jobs = $this->summary($sections, 'face_jobs');
        $breaches = array_values(array_filter(
            $payload['threshold_breaches'] ?? [],
            fn (mixed $breach): bool => is_array($breach)
        ));

        return [
            'version' => $payload['version'] ?? 1,
            'compact' => true,
            'mode' => $payload['mode'] ?? 'observe',
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'window_hours' => $payload['window_hours'] ?? null,
            'captured_at' => $payload['captured_at'] ?? null,
            'status' => $payload['status'] ?? 'unknown',
            'threshold_breaches' => [
                'count' => count($breaches),
                'ids' => array_values(array_filter(array_map(
                    fn (array $breach): ?string => isset($breach['id']) ? (string) $breach['id'] : null,
                    $breaches
                ))),
                'status_counts' => $this->statusCounts($breaches),
            ],
            'recommendation_count' => count($payload['recommendations'] ?? []),
            'sections' => [
                'mysql_face_registry' => [
                    'status' => $this->sectionStatus($sections, 'mysql_face_registry'),
                    'total_faces' => $this->intValue($registry['total_faces'] ?? 0),
                    'visible_faces' => $this->intValue($registry['visible_faces'] ?? 0),
                    'genealogy_linked_faces' => $this->intValue($registry['genealogy_linked_faces'] ?? 0),
                    'named_only_faces' => $this->intValue($registry['named_only_faces'] ?? 0),
                    'stale_named_only_faces' => $this->intValue($registry['stale_named_only_faces'] ?? 0),
                    'open_named_only_faces' => $this->intValue($registry['open_named_only_faces'] ?? 0),
                    'stale_open_named_only_faces' => $this->intValue($registry['stale_open_named_only_faces'] ?? 0),
                    'terminal_decided_named_only_faces' => $this->intValue($registry['terminal_decided_named_only_faces'] ?? 0),
                    'oldest_named_only_updated_at' => $this->nullableString($registry['oldest_named_only_updated_at'] ?? null),
                    'newest_named_only_updated_at' => $this->nullableString($registry['newest_named_only_updated_at'] ?? null),
                    'unclustered_visible_faces' => $this->intValue($registry['unclustered_visible_faces'] ?? 0),
                ],
                'review_queue' => [
                    'status' => $this->sectionStatus($sections, 'review_queue'),
                    'total_queue_items' => $this->intValue($queue['total_queue_items'] ?? 0),
                    'pending_items' => $this->intValue($queue['pending_items'] ?? 0),
                    'stale_pending_items' => $this->intValue($queue['stale_pending_items'] ?? 0),
                    'stale_no_match_pending' => $this->intValue($queue['stale_no_match_pending'] ?? 0),
                    'recent_updates' => $this->intValue($queue['recent_updates'] ?? 0),
                    'oldest_pending_at' => $this->nullableString($queue['oldest_pending_at'] ?? null),
                ],
                'candidate_decisions' => [
                    'status' => $this->sectionStatus($sections, 'candidate_decisions'),
                    'decision_rows' => $this->intValue($decisions['decision_rows'] ?? 0),
                    'decided_faces' => $this->intValue($decisions['decided_faces'] ?? 0),
                    'recent_decisions' => $this->intValue($decisions['recent_decisions'] ?? 0),
                    'latest_decision_at' => $this->nullableString($decisions['latest_decision_at'] ?? null),
                    'terminal_decisions' => $this->intValue($decisions['terminal_decisions'] ?? 0),
                    'actions' => [
                        'keep_name_only' => $this->intValue($decisions['keep_name_only'] ?? 0),
                        'outside_tree' => $this->intValue($decisions['outside_tree'] ?? 0),
                        'too_vague' => $this->intValue($decisions['too_vague'] ?? 0),
                        'not_this_person' => $this->intValue($decisions['not_this_person'] ?? 0),
                        'defer' => $this->intValue($decisions['deferred'] ?? 0),
                    ],
                ],
                'bridge_alignment' => [
                    'status' => $this->sectionStatus($sections, 'bridge_alignment'),
                    'linked_faces' => $this->intValue($bridge['linked_faces'] ?? 0),
                    'aligned_faces' => $this->intValue($bridge['aligned_faces'] ?? 0),
                    'missing_media_links' => $this->intValue($bridge['missing_media_links'] ?? 0),
                    'missing_person_media_links' => $this->intValue($bridge['missing_person_media_links'] ?? 0),
                    'face_confirmed_person_media' => $this->intValue($bridge['face_confirmed_person_media'] ?? 0),
                    'person_media_with_regions' => $this->intValue($bridge['person_media_with_regions'] ?? 0),
                ],
                'postgres_face_vectors' => [
                    'status' => $this->sectionStatus($sections, 'postgres_face_vectors'),
                    'total_embeddings' => $this->intValue($vectors['total_embeddings'] ?? 0),
                    'linked_registry_embeddings' => $this->intValue($vectors['linked_registry_embeddings'] ?? 0),
                    'clustered_embeddings' => $this->intValue($vectors['clustered_embeddings'] ?? 0),
                    'total_clusters' => $this->intValue($vectors['total_clusters'] ?? 0),
                    'confirmed_clusters' => $this->intValue($vectors['confirmed_clusters'] ?? 0),
                    'genealogy_linked_clusters' => $this->intValue($vectors['genealogy_linked_clusters'] ?? 0),
                    'unreviewed_clusters' => $this->intValue($vectors['unreviewed_clusters'] ?? 0),
                ],
                'face_jobs' => [
                    'status' => $this->sectionStatus($sections, 'face_jobs'),
                    'total_jobs' => $this->intValue($jobs['total_jobs'] ?? 0),
                    'enabled_jobs' => $this->intValue($jobs['enabled_jobs'] ?? 0),
                    'running_jobs' => $this->intValue($jobs['running_jobs'] ?? 0),
                    'recent_runs' => $this->intValue($jobs['recent_runs'] ?? 0),
                    'recent_success_runs' => $this->intValue($jobs['recent_success_runs'] ?? 0),
                    'recent_failed_runs' => $this->intValue($jobs['recent_failed_runs'] ?? 0),
                    'latest_run_at' => $this->nullableString($jobs['latest_run_at'] ?? null),
                    'next_run_at' => $this->nullableString($jobs['next_run_at'] ?? null),
                ],
            ],
        ];
    }

    public function toCompactMarkdown(array $payload): string
    {
        $compact = $this->toCompactPayload($payload);
        $sections = $compact['sections'];

        $lines = [
            '# Face Telemetry Compact Report',
            '',
            '- Mode: `'.$compact['mode'].'`',
            '- Status: `'.$compact['status'].'`',
            '- Captured: `'.($compact['captured_at'] ?? 'unknown').'`',
            '- Window Hours: `'.($compact['window_hours'] ?? 'unknown').'`',
            '- Threshold Breaches: `'.$compact['threshold_breaches']['count'].'`',
            '- Breach IDs: `'.$this->joinIds($compact['threshold_breaches']['ids']).'`',
            '- Recommendations: `'.$compact['recommendation_count'].'`',
            '',
            '## Headlines',
            '',
            sprintf(
                '- Face registry: `%s`, total=`%s`, visible=`%s`, linked=`%s`, named_only=`%s`, open_named_only=`%s`, stale_open_named_only=`%s`, terminal_named_only=`%s`, stale_named_only_faces=`%s`, oldest_named_only_updated_at=`%s`, newest_named_only_updated_at=`%s`, unclustered_visible=`%s`',
                $sections['mysql_face_registry']['status'],
                $sections['mysql_face_registry']['total_faces'],
                $sections['mysql_face_registry']['visible_faces'],
                $sections['mysql_face_registry']['genealogy_linked_faces'],
                $sections['mysql_face_registry']['named_only_faces'],
                $sections['mysql_face_registry']['open_named_only_faces'],
                $sections['mysql_face_registry']['stale_open_named_only_faces'],
                $sections['mysql_face_registry']['terminal_decided_named_only_faces'],
                $sections['mysql_face_registry']['stale_named_only_faces'],
                $sections['mysql_face_registry']['oldest_named_only_updated_at'] ?? 'none',
                $sections['mysql_face_registry']['newest_named_only_updated_at'] ?? 'none',
                $sections['mysql_face_registry']['unclustered_visible_faces']
            ),
            sprintf(
                '- Review queue: `%s`, pending=`%s`, stale=`%s`, stale_no_match=`%s`, recent_updates=`%s`, oldest_pending=`%s`',
                $sections['review_queue']['status'],
                $sections['review_queue']['pending_items'],
                $sections['review_queue']['stale_pending_items'],
                $sections['review_queue']['stale_no_match_pending'],
                $sections['review_queue']['recent_updates'],
                $sections['review_queue']['oldest_pending_at'] ?? 'none'
            ),
            sprintf(
                '- Candidate decisions: `%s`, rows=`%s`, recent=`%s`, terminal=`%s`, latest=`%s`',
                $sections['candidate_decisions']['status'],
                $sections['candidate_decisions']['decision_rows'],
                $sections['candidate_decisions']['recent_decisions'],
                $sections['candidate_decisions']['terminal_decisions'],
                $sections['candidate_decisions']['latest_decision_at'] ?? 'none'
            ),
            sprintf(
                '- Bridge alignment: `%s`, linked=`%s`, aligned=`%s`, missing_media=`%s`, missing_person_media=`%s`',
                $sections['bridge_alignment']['status'],
                $sections['bridge_alignment']['linked_faces'],
                $sections['bridge_alignment']['aligned_faces'],
                $sections['bridge_alignment']['missing_media_links'],
                $sections['bridge_alignment']['missing_person_media_links']
            ),
            sprintf(
                '- Vectors: `%s`, embeddings=`%s`, linked=`%s`, clustered=`%s`, clusters=`%s`',
                $sections['postgres_face_vectors']['status'],
                $sections['postgres_face_vectors']['total_embeddings'],
                $sections['postgres_face_vectors']['linked_registry_embeddings'],
                $sections['postgres_face_vectors']['clustered_embeddings'],
                $sections['postgres_face_vectors']['total_clusters']
            ),
            sprintf(
                '- Face jobs: `%s`, total=`%s`, enabled=`%s`, running=`%s`, recent_failed=`%s`, next=`%s`',
                $sections['face_jobs']['status'],
                $sections['face_jobs']['total_jobs'],
                $sections['face_jobs']['enabled_jobs'],
                $sections['face_jobs']['running_jobs'],
                $sections['face_jobs']['recent_failed_runs'],
                $sections['face_jobs']['next_run_at'] ?? 'none'
            ),
        ];

        return implode("\n", $lines)."\n";
    }

    public function toCompactText(array $payload): string
    {
        $compact = $this->toCompactPayload($payload);
        $sections = $compact['sections'];

        return implode("\n", [
            sprintf(
                'Face telemetry compact: %s mode=%s dry_run=%s window_hours=%s captured=%s',
                $compact['status'],
                $compact['mode'],
                $compact['dry_run'] ? 'true' : 'false',
                $compact['window_hours'] ?? '-',
                $compact['captured_at'] ?? '-'
            ),
            sprintf(
                'thresholds: count=%s ids=%s recommendations=%s',
                $compact['threshold_breaches']['count'],
                $this->joinIds($compact['threshold_breaches']['ids']),
                $compact['recommendation_count']
            ),
            sprintf(
                'face-registry: %s total=%s visible=%s linked=%s named_only=%s open_named_only=%s stale_open_named_only=%s terminal_named_only=%s stale_named_only_faces=%s oldest_named_only_updated_at=%s newest_named_only_updated_at=%s unclustered_visible=%s',
                $sections['mysql_face_registry']['status'],
                $sections['mysql_face_registry']['total_faces'],
                $sections['mysql_face_registry']['visible_faces'],
                $sections['mysql_face_registry']['genealogy_linked_faces'],
                $sections['mysql_face_registry']['named_only_faces'],
                $sections['mysql_face_registry']['open_named_only_faces'],
                $sections['mysql_face_registry']['stale_open_named_only_faces'],
                $sections['mysql_face_registry']['terminal_decided_named_only_faces'],
                $sections['mysql_face_registry']['stale_named_only_faces'],
                $sections['mysql_face_registry']['oldest_named_only_updated_at'] ?? 'none',
                $sections['mysql_face_registry']['newest_named_only_updated_at'] ?? 'none',
                $sections['mysql_face_registry']['unclustered_visible_faces']
            ),
            sprintf(
                'review-queue: %s total=%s pending=%s stale=%s stale_no_match=%s recent_updates=%s oldest_pending=%s',
                $sections['review_queue']['status'],
                $sections['review_queue']['total_queue_items'],
                $sections['review_queue']['pending_items'],
                $sections['review_queue']['stale_pending_items'],
                $sections['review_queue']['stale_no_match_pending'],
                $sections['review_queue']['recent_updates'],
                $sections['review_queue']['oldest_pending_at'] ?? 'none'
            ),
            sprintf(
                'candidate-decisions: %s rows=%s faces=%s recent=%s latest=%s terminal=%s keep=%s outside=%s vague=%s not-this=%s defer=%s',
                $sections['candidate_decisions']['status'],
                $sections['candidate_decisions']['decision_rows'],
                $sections['candidate_decisions']['decided_faces'],
                $sections['candidate_decisions']['recent_decisions'],
                $sections['candidate_decisions']['latest_decision_at'] ?? 'none',
                $sections['candidate_decisions']['terminal_decisions'],
                $sections['candidate_decisions']['actions']['keep_name_only'],
                $sections['candidate_decisions']['actions']['outside_tree'],
                $sections['candidate_decisions']['actions']['too_vague'],
                $sections['candidate_decisions']['actions']['not_this_person'],
                $sections['candidate_decisions']['actions']['defer']
            ),
            sprintf(
                'bridge-alignment: %s linked=%s aligned=%s missing_media=%s missing_person_media=%s confirmed_media=%s region_rows=%s',
                $sections['bridge_alignment']['status'],
                $sections['bridge_alignment']['linked_faces'],
                $sections['bridge_alignment']['aligned_faces'],
                $sections['bridge_alignment']['missing_media_links'],
                $sections['bridge_alignment']['missing_person_media_links'],
                $sections['bridge_alignment']['face_confirmed_person_media'],
                $sections['bridge_alignment']['person_media_with_regions']
            ),
            sprintf(
                'vectors: %s embeddings=%s linked=%s clustered=%s clusters=%s confirmed=%s genealogy_linked=%s unreviewed=%s',
                $sections['postgres_face_vectors']['status'],
                $sections['postgres_face_vectors']['total_embeddings'],
                $sections['postgres_face_vectors']['linked_registry_embeddings'],
                $sections['postgres_face_vectors']['clustered_embeddings'],
                $sections['postgres_face_vectors']['total_clusters'],
                $sections['postgres_face_vectors']['confirmed_clusters'],
                $sections['postgres_face_vectors']['genealogy_linked_clusters'],
                $sections['postgres_face_vectors']['unreviewed_clusters']
            ),
            sprintf(
                'face-jobs: %s total=%s enabled=%s running=%s recent=%s success=%s failed=%s latest=%s next=%s',
                $sections['face_jobs']['status'],
                $sections['face_jobs']['total_jobs'],
                $sections['face_jobs']['enabled_jobs'],
                $sections['face_jobs']['running_jobs'],
                $sections['face_jobs']['recent_runs'],
                $sections['face_jobs']['recent_success_runs'],
                $sections['face_jobs']['recent_failed_runs'],
                $sections['face_jobs']['latest_run_at'] ?? 'none',
                $sections['face_jobs']['next_run_at'] ?? 'none'
            ),
        ])."\n";
    }

    private function collectMysqlFaceRegistry(int $hours): array
    {
        try {
            $row = DB::selectOne(
                "SELECT
                    COUNT(*) AS total_faces,
                    SUM(CASE WHEN frf.hidden = 0 THEN 1 ELSE 0 END) AS visible_faces,
                    SUM(CASE WHEN frf.hidden = 1 THEN 1 ELSE 0 END) AS hidden_faces,
                    SUM(CASE WHEN frf.verified = 1 THEN 1 ELSE 0 END) AS verified_faces,
                    SUM(CASE WHEN frf.genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) AS genealogy_linked_faces,
                    SUM(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL THEN 1 ELSE 0 END) AS named_only_faces,
                    SUM(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL AND frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS stale_named_only_faces,
                    SUM(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 THEN 1 ELSE 0 END) AS open_named_only_faces,
                    SUM(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 0 AND frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS stale_open_named_only_faces,
                    SUM(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL AND COALESCE(candidate_decisions.has_terminal_candidate_decision, 0) = 1 THEN 1 ELSE 0 END) AS terminal_decided_named_only_faces,
                    MIN(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL THEN frf.updated_at ELSE NULL END) AS oldest_named_only_updated_at,
                    MAX(CASE WHEN frf.hidden = 0 AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND frf.genealogy_person_id IS NULL THEN frf.updated_at ELSE NULL END) AS newest_named_only_updated_at,
                    SUM(CASE WHEN frf.hidden = 0 AND (frf.cluster_id IS NULL OR frf.cluster_id = 0) THEN 1 ELSE 0 END) AS unclustered_visible_faces,
                    SUM(CASE WHEN frf.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS created_recent_faces
                 FROM file_registry_faces frf
                 LEFT JOIN (
                    SELECT
                        file_registry_face_id,
                        MAX(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.terminal')) = 'true' THEN 1 ELSE 0 END) AS has_terminal_candidate_decision
                    FROM genealogy_face_match_queue
                    WHERE file_registry_face_id IS NOT NULL
                    GROUP BY file_registry_face_id
                 ) candidate_decisions ON candidate_decisions.file_registry_face_id = frf.id",
                [$hours, $hours, $hours]
            );

            $totalFaces = $this->intValue($row->total_faces ?? 0);

            return [
                'status' => $totalFaces > 0 ? 'observe_ok' : 'observe_warning',
                'source' => 'mysql.file_registry_faces',
                'summary' => [
                    'total_faces' => $totalFaces,
                    'visible_faces' => $this->intValue($row->visible_faces ?? 0),
                    'hidden_faces' => $this->intValue($row->hidden_faces ?? 0),
                    'verified_faces' => $this->intValue($row->verified_faces ?? 0),
                    'genealogy_linked_faces' => $this->intValue($row->genealogy_linked_faces ?? 0),
                    'named_only_faces' => $this->intValue($row->named_only_faces ?? 0),
                    'stale_named_only_faces' => $this->intValue($row->stale_named_only_faces ?? 0),
                    'open_named_only_faces' => $this->intValue($row->open_named_only_faces ?? 0),
                    'stale_open_named_only_faces' => $this->intValue($row->stale_open_named_only_faces ?? 0),
                    'terminal_decided_named_only_faces' => $this->intValue($row->terminal_decided_named_only_faces ?? 0),
                    'oldest_named_only_updated_at' => $this->nullableString($row->oldest_named_only_updated_at ?? null),
                    'newest_named_only_updated_at' => $this->nullableString($row->newest_named_only_updated_at ?? null),
                    'unclustered_visible_faces' => $this->intValue($row->unclustered_visible_faces ?? 0),
                    'created_recent_faces' => $this->intValue($row->created_recent_faces ?? 0),
                ],
            ];
        } catch (Throwable $e) {
            return $this->failedSection('mysql.file_registry_faces', $e);
        }
    }

    private function collectReviewQueue(int $hours): array
    {
        try {
            $row = DB::selectOne(
                "SELECT
                    COUNT(*) AS total_queue_items,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_items,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_items,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_items,
                    SUM(CASE WHEN status = 'auto_linked' THEN 1 ELSE 0 END) AS auto_linked_items,
                    SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) AS ignored_items,
                    SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS stale_pending_items,
                    SUM(CASE WHEN status = 'pending' AND match_type = 'no_match' AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS stale_no_match_pending,
                    SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS recent_updates,
                    MIN(CASE WHEN status = 'pending' THEN created_at ELSE NULL END) AS oldest_pending_at
                 FROM genealogy_face_match_queue",
                [$hours, $hours, $hours]
            );

            $pending = $this->intValue($row->pending_items ?? 0);
            $stalePending = $this->intValue($row->stale_pending_items ?? 0);

            return [
                'status' => $pending > 0 || $stalePending > 0 ? 'observe_warning' : 'observe_ok',
                'source' => 'mysql.genealogy_face_match_queue',
                'summary' => [
                    'total_queue_items' => $this->intValue($row->total_queue_items ?? 0),
                    'pending_items' => $pending,
                    'approved_items' => $this->intValue($row->approved_items ?? 0),
                    'rejected_items' => $this->intValue($row->rejected_items ?? 0),
                    'auto_linked_items' => $this->intValue($row->auto_linked_items ?? 0),
                    'ignored_items' => $this->intValue($row->ignored_items ?? 0),
                    'stale_pending_items' => $stalePending,
                    'stale_no_match_pending' => $this->intValue($row->stale_no_match_pending ?? 0),
                    'recent_updates' => $this->intValue($row->recent_updates ?? 0),
                    'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
                ],
            ];
        } catch (Throwable $e) {
            return $this->failedSection('mysql.genealogy_face_match_queue', $e);
        }
    }

    private function collectCandidateDecisions(int $hours): array
    {
        try {
            $row = DB::selectOne(
                "SELECT
                    COUNT(*) AS decision_rows,
                    COUNT(DISTINCT file_registry_face_id) AS decided_faces,
                    SUM(CASE WHEN action = 'keep_name_only' THEN 1 ELSE 0 END) AS keep_name_only,
                    SUM(CASE WHEN action = 'outside_tree' THEN 1 ELSE 0 END) AS outside_tree,
                    SUM(CASE WHEN action = 'too_vague' THEN 1 ELSE 0 END) AS too_vague,
                    SUM(CASE WHEN action = 'not_this_person' THEN 1 ELSE 0 END) AS not_this_person,
                    SUM(CASE WHEN action = 'defer' THEN 1 ELSE 0 END) AS deferred,
                    SUM(CASE WHEN terminal = 'true' THEN 1 ELSE 0 END) AS terminal_decisions,
                    SUM(CASE WHEN decided_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR) THEN 1 ELSE 0 END) AS recent_decisions,
                    DATE_FORMAT(MAX(decided_at), '%Y-%m-%dT%H:%i:%sZ') AS latest_decision_at
                 FROM (
                    SELECT
                        file_registry_face_id,
                        JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.action')) AS action,
                        JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.terminal')) AS terminal,
                        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.latest_candidate_decision.decided_at')), '%Y-%m-%dT%H:%i:%sZ') AS decided_at
                    FROM genealogy_face_match_queue
                 ) decisions
                 WHERE action IN ('keep_name_only', 'outside_tree', 'too_vague', 'not_this_person', 'defer')",
                [$hours]
            );

            return [
                'status' => 'observe_ok',
                'source' => 'mysql.genealogy_face_match_queue.match_details',
                'summary' => [
                    'decision_rows' => $this->intValue($row->decision_rows ?? 0),
                    'decided_faces' => $this->intValue($row->decided_faces ?? 0),
                    'keep_name_only' => $this->intValue($row->keep_name_only ?? 0),
                    'outside_tree' => $this->intValue($row->outside_tree ?? 0),
                    'too_vague' => $this->intValue($row->too_vague ?? 0),
                    'not_this_person' => $this->intValue($row->not_this_person ?? 0),
                    'deferred' => $this->intValue($row->deferred ?? 0),
                    'terminal_decisions' => $this->intValue($row->terminal_decisions ?? 0),
                    'recent_decisions' => $this->intValue($row->recent_decisions ?? 0),
                    'latest_decision_at' => $this->nullableString($row->latest_decision_at ?? null),
                ],
            ];
        } catch (Throwable $e) {
            return $this->failedSection('mysql.face_candidate_decisions', $e);
        }
    }

    private function collectBridgeAlignment(): array
    {
        try {
            $alignment = DB::selectOne(
                "SELECT
                    COUNT(DISTINCT frf.id) AS linked_faces,
                    COUNT(DISTINCT CASE WHEN gm.id IS NULL THEN frf.id ELSE NULL END) AS missing_media_links,
                    COUNT(DISTINCT CASE WHEN gm.id IS NOT NULL AND gpm.id IS NULL THEN frf.id ELSE NULL END) AS missing_person_media_links,
                    COUNT(DISTINCT CASE WHEN gpm.id IS NOT NULL THEN frf.id ELSE NULL END) AS aligned_faces
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                 LEFT JOIN genealogy_media gm
                   ON gm.tree_id = gp.tree_id
                  AND (
                    gm.nextcloud_path = COALESCE(NULLIF(fr.current_path, ''), fr.original_path)
                    OR gm.original_path = fr.original_path
                  )
                 LEFT JOIN genealogy_person_media gpm
                   ON gpm.person_id = frf.genealogy_person_id
                  AND gpm.media_id = gm.id
                 WHERE frf.hidden = 0
                   AND frf.genealogy_person_id IS NOT NULL"
            );

            $personMedia = DB::selectOne(
                'SELECT
                    COUNT(*) AS face_confirmed_person_media,
                    SUM(CASE WHEN face_region_x IS NOT NULL OR face_region_y IS NOT NULL OR face_region_w IS NOT NULL OR face_region_h IS NOT NULL THEN 1 ELSE 0 END) AS person_media_with_regions
                 FROM genealogy_person_media
                 WHERE face_confirmed = 1
                    OR face_region_x IS NOT NULL
                    OR face_region_y IS NOT NULL
                    OR face_region_w IS NOT NULL
                    OR face_region_h IS NOT NULL'
            );

            $missingMedia = $this->intValue($alignment->missing_media_links ?? 0);
            $missingPersonMedia = $this->intValue($alignment->missing_person_media_links ?? 0);
            $gapSamples = [];
            if ($missingMedia + $missingPersonMedia > 0) {
                $gapSamples = array_map(
                    fn (object $row): array => [
                        'face_id' => $this->intValue($row->face_id ?? 0),
                        'file_registry_id' => $this->intValue($row->file_registry_id ?? 0),
                        'person_id' => $this->intValue($row->person_id ?? 0),
                        'tree_id' => $this->intValue($row->tree_id ?? 0),
                        'genealogy_media_id' => isset($row->genealogy_media_id) ? $this->intValue($row->genealogy_media_id) : null,
                        'gap_type' => (string) ($row->gap_type ?? 'unknown'),
                        'has_registry_path' => ((int) ($row->has_registry_path ?? 0)) === 1,
                    ],
                    DB::select(
                        "SELECT
                            frf.id AS face_id,
                            frf.file_registry_id,
                            frf.genealogy_person_id AS person_id,
                            gp.tree_id,
                            gm.id AS genealogy_media_id,
                            CASE
                                WHEN gm.id IS NULL THEN 'missing_genealogy_media'
                                WHEN gpm.id IS NULL THEN 'missing_person_media'
                                ELSE 'aligned'
                            END AS gap_type,
                            CASE
                                WHEN COALESCE(NULLIF(fr.current_path, ''), fr.original_path) IS NULL
                                  OR COALESCE(NULLIF(fr.current_path, ''), fr.original_path) = ''
                                THEN 0 ELSE 1
                            END AS has_registry_path
                         FROM file_registry_faces frf
                         JOIN file_registry fr ON fr.id = frf.file_registry_id
                         JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                         LEFT JOIN genealogy_media gm
                           ON gm.tree_id = gp.tree_id
                          AND (
                            gm.nextcloud_path = COALESCE(NULLIF(fr.current_path, ''), fr.original_path)
                            OR gm.original_path = fr.original_path
                          )
                         LEFT JOIN genealogy_person_media gpm
                           ON gpm.person_id = frf.genealogy_person_id
                          AND gpm.media_id = gm.id
                         WHERE frf.hidden = 0
                           AND frf.genealogy_person_id IS NOT NULL
                           AND (gm.id IS NULL OR gpm.id IS NULL)
                         ORDER BY frf.updated_at DESC, frf.id DESC
                         LIMIT 5"
                    )
                );
            }

            return [
                'status' => $missingMedia + $missingPersonMedia > 0 ? 'review_required' : 'observe_ok',
                'source' => 'mysql.file_registry_faces+genealogy_media+genealogy_person_media',
                'summary' => [
                    'linked_faces' => $this->intValue($alignment->linked_faces ?? 0),
                    'aligned_faces' => $this->intValue($alignment->aligned_faces ?? 0),
                    'missing_media_links' => $missingMedia,
                    'missing_person_media_links' => $missingPersonMedia,
                    'face_confirmed_person_media' => $this->intValue($personMedia->face_confirmed_person_media ?? 0),
                    'person_media_with_regions' => $this->intValue($personMedia->person_media_with_regions ?? 0),
                    'gap_samples' => $gapSamples,
                ],
            ];
        } catch (Throwable $e) {
            return $this->failedSection('mysql.face_bridge_alignment', $e);
        }
    }

    private function collectPostgresFaceVectors(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            $embeddings = $db->selectOne(
                'SELECT
                    COUNT(*) AS total_embeddings,
                    COUNT(file_registry_face_id) AS linked_registry_embeddings,
                    SUM(CASE WHEN person_cluster_id IS NOT NULL THEN 1 ELSE 0 END) AS clustered_embeddings
                 FROM face_embeddings'
            );

            $clusters = $db->selectOne(
                "SELECT
                    COUNT(*) AS total_clusters,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_clusters,
                    SUM(CASE WHEN genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) AS genealogy_linked_clusters,
                    SUM(CASE WHEN status = 'unreviewed' THEN 1 ELSE 0 END) AS unreviewed_clusters,
                    SUM(CASE WHEN merged_into_id IS NOT NULL THEN 1 ELSE 0 END) AS merged_clusters
                 FROM person_clusters"
            );

            $totalEmbeddings = $this->intValue($embeddings->total_embeddings ?? 0);
            $linkedRegistry = $this->intValue($embeddings->linked_registry_embeddings ?? 0);

            return [
                'status' => $linkedRegistry < $totalEmbeddings ? 'observe_warning' : 'observe_ok',
                'source' => 'pgsql_rag.face_embeddings+person_clusters',
                'summary' => [
                    'total_embeddings' => $totalEmbeddings,
                    'linked_registry_embeddings' => $linkedRegistry,
                    'clustered_embeddings' => $this->intValue($embeddings->clustered_embeddings ?? 0),
                    'total_clusters' => $this->intValue($clusters->total_clusters ?? 0),
                    'confirmed_clusters' => $this->intValue($clusters->confirmed_clusters ?? 0),
                    'genealogy_linked_clusters' => $this->intValue($clusters->genealogy_linked_clusters ?? 0),
                    'unreviewed_clusters' => $this->intValue($clusters->unreviewed_clusters ?? 0),
                    'merged_clusters' => $this->intValue($clusters->merged_clusters ?? 0),
                ],
            ];
        } catch (Throwable $e) {
            return $this->failedSection('pgsql_rag.face_vectors', $e);
        }
    }

    private function collectFaceJobs(int $hours): array
    {
        try {
            $jobs = DB::selectOne(
                "SELECT
                    COUNT(*) AS total_jobs,
                    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled_jobs,
                    SUM(CASE WHEN last_run_status = 'success' THEN 1 ELSE 0 END) AS last_success_jobs,
                    SUM(CASE WHEN last_run_status IN ('failed', 'timeout') THEN 1 ELSE 0 END) AS last_failed_jobs,
                    SUM(CASE WHEN last_run_status = 'running' THEN 1 ELSE 0 END) AS running_jobs,
                    MAX(last_run_at) AS latest_run_at,
                    MIN(next_run_at) AS next_run_at
                 FROM scheduled_jobs
                 WHERE LOWER(name) LIKE '%face%'
                    OR LOWER(command) LIKE '%face%'
                    OR command LIKE '%--type=faces%'"
            );

            $runs = DB::selectOne(
                "SELECT
                    COUNT(*) AS recent_runs,
                    SUM(CASE WHEN r.status = 'success' THEN 1 ELSE 0 END) AS recent_success_runs,
                    SUM(CASE WHEN r.status IN ('failed', 'timeout') THEN 1 ELSE 0 END) AS recent_failed_runs,
                    MAX(r.completed_at) AS latest_completed_at
                 FROM scheduled_job_runs r
                 JOIN scheduled_jobs j ON j.id = r.scheduled_job_id
                 WHERE r.started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                   AND (
                    LOWER(j.name) LIKE '%face%'
                    OR LOWER(j.command) LIKE '%face%'
                    OR j.command LIKE '%--type=faces%'
                   )",
                [$hours]
            );

            $totalJobs = $this->intValue($jobs->total_jobs ?? 0);
            $failedJobs = $this->intValue($jobs->last_failed_jobs ?? 0);
            $failedRuns = $this->intValue($runs->recent_failed_runs ?? 0);

            return [
                'status' => $totalJobs === 0 || $failedJobs > 0 || $failedRuns > 0 ? 'observe_warning' : 'observe_ok',
                'source' => 'mysql.scheduled_jobs+scheduled_job_runs',
                'summary' => [
                    'total_jobs' => $totalJobs,
                    'enabled_jobs' => $this->intValue($jobs->enabled_jobs ?? 0),
                    'last_success_jobs' => $this->intValue($jobs->last_success_jobs ?? 0),
                    'last_failed_jobs' => $failedJobs,
                    'running_jobs' => $this->intValue($jobs->running_jobs ?? 0),
                    'latest_run_at' => $this->nullableString($jobs->latest_run_at ?? null),
                    'next_run_at' => $this->nullableString($jobs->next_run_at ?? null),
                    'recent_runs' => $this->intValue($runs->recent_runs ?? 0),
                    'recent_success_runs' => $this->intValue($runs->recent_success_runs ?? 0),
                    'recent_failed_runs' => $failedRuns,
                    'latest_completed_at' => $this->nullableString($runs->latest_completed_at ?? null),
                ],
            ];
        } catch (Throwable $e) {
            return $this->failedSection('mysql.face_jobs', $e);
        }
    }

    private function evaluateThresholds(array $sections): array
    {
        $breaches = [];
        $recommendations = [];

        $registry = $sections['mysql_face_registry']['summary'] ?? [];
        $namedOnly = (int) ($registry['named_only_faces'] ?? 0);
        if ($namedOnly > 0) {
            $breaches[] = [
                'id' => 'face-named-only-backlog',
                'status' => 'observe_warning',
                'message' => "{$namedOnly} visible named face(s) are not linked to genealogy persons.",
            ];
            $recommendations[] = 'Review named-only face rows and either link to genealogy persons or keep them explicitly as named-only.';
        }

        $queue = $sections['review_queue']['summary'] ?? [];
        $pending = (int) ($queue['pending_items'] ?? 0);
        $stalePending = (int) ($queue['stale_pending_items'] ?? 0);
        if ($pending > 0) {
            $breaches[] = [
                'id' => 'face-review-pending',
                'status' => 'observe_warning',
                'message' => "{$pending} face review queue item(s) are pending.",
            ];
            $recommendations[] = 'Use the face review queue to approve, reject, ignore, or keep pending candidates before widening automation.';
        }
        if ($stalePending > 0) {
            $breaches[] = [
                'id' => 'face-review-stale',
                'status' => 'observe_warning',
                'message' => "{$stalePending} pending face review queue item(s) are older than the report window.",
            ];
        }

        $bridge = $sections['bridge_alignment']['summary'] ?? [];
        $missingLinks = (int) ($bridge['missing_media_links'] ?? 0) + (int) ($bridge['missing_person_media_links'] ?? 0);
        if ($missingLinks > 0) {
            $breaches[] = [
                'id' => 'face-bridge-missing-links',
                'status' => 'review_required',
                'message' => "{$missingLinks} genealogy-linked face(s) are missing media or person-media bridge rows.",
            ];
            $recommendations[] = 'Reconcile face bridge rows through FaceLinkBridgeService before relying on exports or writeback evidence.';
        }

        $vectors = $sections['postgres_face_vectors']['summary'] ?? [];
        $totalEmbeddings = (int) ($vectors['total_embeddings'] ?? 0);
        $linkedEmbeddings = (int) ($vectors['linked_registry_embeddings'] ?? 0);
        if ($linkedEmbeddings < $totalEmbeddings) {
            $missingEmbeddings = $totalEmbeddings - $linkedEmbeddings;
            $breaches[] = [
                'id' => 'face-pgvector-unlinked',
                'status' => 'observe_warning',
                'message' => "{$missingEmbeddings} pgvector face embedding(s) lack a file_registry_face_id link.",
            ];
        }

        $jobs = $sections['face_jobs']['summary'] ?? [];
        $totalJobs = (int) ($jobs['total_jobs'] ?? 0);
        $failedJobs = (int) ($jobs['last_failed_jobs'] ?? 0);
        $failedRuns = (int) ($jobs['recent_failed_runs'] ?? 0);
        if ($totalJobs === 0) {
            $breaches[] = [
                'id' => 'face-jobs-missing',
                'status' => 'observe_warning',
                'message' => 'No face-related scheduled jobs were found.',
            ];
        }
        if ($failedJobs + $failedRuns > 0) {
            $breaches[] = [
                'id' => 'face-jobs-failures',
                'status' => 'observe_warning',
                'message' => ($failedJobs + $failedRuns).' face-related scheduled job failure signal(s) were observed.',
            ];
            $recommendations[] = 'Review face-related scheduled job output before treating face backlog or cluster telemetry as fresh.';
        }

        return [$breaches, array_values(array_unique($recommendations))];
    }

    private function failedSection(string $source, Throwable $e): array
    {
        return [
            'status' => 'observe_warning',
            'source' => $source,
            'error' => class_basename($e).' '.$e->getMessage(),
        ];
    }

    private function worstStatus(array $statuses): string
    {
        $worst = 'observe_ok';

        foreach ($statuses as $status) {
            if (! is_string($status)) {
                continue;
            }

            if ((self::STATUS_RANK[$status] ?? 0) > (self::STATUS_RANK[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private function summary(array $sections, string $name): array
    {
        $summary = $sections[$name]['summary'] ?? [];

        return is_array($summary) ? $summary : [];
    }

    private function sectionStatus(array $sections, string $name): string
    {
        return (string) ($sections[$name]['status'] ?? 'unknown');
    }

    private function statusCounts(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = (string) ($item['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function joinIds(array $ids): string
    {
        return $ids === [] ? 'none' : implode(',', $ids);
    }

    private function intValue(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
