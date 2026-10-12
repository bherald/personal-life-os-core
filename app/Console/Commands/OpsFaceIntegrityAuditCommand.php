<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpsFaceIntegrityAuditCommand extends Command
{
    protected $signature = 'ops:face-integrity-audit
        {--limit=25 : Maximum sample rows to include per finding}
        {--repair-safe : Plan deterministic repairs}
        {--confirm-repair : Actually apply deterministic repairs}
        {--json : Emit machine-readable JSON}
        {--compact : With --json, omit sample rows from retained scheduled output}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Audit face identity integrity across MySQL face rows and pgvector clusters';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $repairSafe = (bool) $this->option('repair-safe');
        $confirmRepair = $repairSafe && (bool) $this->option('confirm-repair');

        $payload = $this->basePayload($limit, $repairSafe, $confirmRepair);

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $compact = (bool) $this->option('compact');
        $payload['checks'] = $this->runChecks($compact ? 0 : $limit);
        $payload['summary'] = array_merge($payload['summary'], $this->summarizeChecks($payload['checks']));
        if ($compact) {
            unset($payload['checks']['samples']);
            $payload['compact'] = true;
            $payload['posture']['samples_included'] = false;
        }

        if ($repairSafe) {
            $payload['repair_plan'] = $this->buildRepairPlan($payload['checks']);
            if ($confirmRepair) {
                $payload['repairs_applied'] = $this->applySafeRepairs($payload['repair_plan']);
                $payload['post_repair_checks'] = $this->runChecks(0);
                $payload['post_repair_summary'] = $this->summarizeChecks($payload['post_repair_checks']);
            }
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function runChecks(int $limit): array
    {
        return [
            'unknown_faces' => $this->countOne(
                "SELECT COUNT(*) AS cnt
                 FROM file_registry_faces
                 WHERE LOWER(TRIM(COALESCE(person_name, ''))) = 'unknown'
                    OR person_name LIKE 'Unknown,%'
                    OR person_name LIKE '%, Unknown,%'
                    OR person_name LIKE '%, Unknown'"
            ),
            'unknown_clusters' => $this->countPgOne(
                "SELECT COUNT(*) AS cnt
                 FROM person_clusters
                 WHERE name ILIKE '%unknown%'
                    OR name ~ '^\\s*,'
                    OR name ~ ',\\s*$'
                    OR name LIKE '%,,%'"
            ),
            'whitespace_only_face_names' => $this->countOne(
                "SELECT COUNT(*) AS cnt
                 FROM file_registry_faces
                 WHERE person_name IS NOT NULL
                   AND person_name <> ''
                   AND TRIM(person_name) = ''"
            ),
            'linked_display_mismatches' => $this->countOne(
                "SELECT COUNT(*) AS cnt
                 FROM file_registry_faces f
                 JOIN genealogy_persons p ON p.id = f.genealogy_person_id
                 WHERE f.genealogy_person_id IS NOT NULL
                   AND TRIM(COALESCE(f.person_name, '')) <> ''
                   AND f.person_name <> TRIM(CONCAT_WS(' ', NULLIF(p.given_name, ''), NULLIF(p.surname, ''), NULLIF(p.suffix, '')))"
            ),
            'approved_queue_not_applied' => $this->countOne(
                "SELECT COUNT(*) AS cnt
                 FROM genealogy_face_match_queue q
                 JOIN file_registry_faces frf ON frf.id = q.file_registry_face_id
                 WHERE q.status = 'approved'
                   AND q.suggested_person_id IS NOT NULL
                   AND (frf.genealogy_person_id IS NULL OR frf.genealogy_person_id <> q.suggested_person_id)"
            ),
            'mysql_duplicate_identity_groups' => $this->countOne(
                "SELECT COUNT(*) AS cnt
                 FROM (
                    SELECT file_registry_id, LOWER(TRIM(person_name)) AS name_key
                    FROM file_registry_faces
                    WHERE hidden = 0
                      AND TRIM(COALESCE(person_name, '')) <> ''
                    GROUP BY file_registry_id, LOWER(TRIM(person_name))
                    HAVING COUNT(*) > 1
                 ) d"
            ),
            'mysql_duplicate_cluster_groups' => $this->countOne(
                'SELECT COUNT(*) AS cnt
                 FROM (
                    SELECT file_registry_id, cluster_id
                    FROM file_registry_faces
                    WHERE hidden = 0
                      AND cluster_id IS NOT NULL
                    GROUP BY file_registry_id, cluster_id
                    HAVING COUNT(*) > 1
                 ) d'
            ),
            'pg_duplicate_cluster_groups' => $this->countPgOne(
                'SELECT COUNT(*) AS cnt
                 FROM (
                    SELECT file_registry_id, person_cluster_id
                    FROM face_embeddings
                    WHERE person_cluster_id IS NOT NULL
                    GROUP BY file_registry_id, person_cluster_id
                    HAVING COUNT(*) > 1
                 ) d'
            ),
            'exact_name_unlinked_unqueued' => $this->countOne($this->exactNameUnlinkedUnqueuedCountSql()),
            'ambiguous_exact_names' => $this->countOne(
                "SELECT COUNT(*) AS cnt
                 FROM (
                    SELECT frf.person_name
                    FROM file_registry_faces frf
                    JOIN genealogy_persons p ON p.tree_id = 4
                     AND TRIM(CONCAT_WS(' ', NULLIF(p.given_name, ''), NULLIF(p.surname, ''), NULLIF(p.suffix, ''))) = frf.person_name
                    WHERE frf.genealogy_person_id IS NULL
                      AND frf.hidden = 0
                      AND TRIM(COALESCE(frf.person_name, '')) <> ''
                    GROUP BY frf.person_name
                    HAVING COUNT(DISTINCT p.id) > 1
                 ) ambiguous"
            ),
            'stale_merged_cluster_refs' => $this->countStaleMergedClusterRefs(),
            'samples' => $limit > 0 ? $this->samples($limit) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function samples(int $limit): array
    {
        return [
            'mixed_name_clusters' => $this->sampleRows(
                "SELECT cluster_id,
                        COUNT(DISTINCT TRIM(person_name)) AS distinct_names,
                        COUNT(*) AS named_faces,
                        GROUP_CONCAT(DISTINCT TRIM(person_name) ORDER BY TRIM(person_name) SEPARATOR ' | ') AS names
                 FROM file_registry_faces
                 WHERE hidden = 0
                   AND cluster_id IS NOT NULL
                   AND TRIM(COALESCE(person_name, '')) <> ''
                   AND LOWER(TRIM(person_name)) <> 'unknown'
                 GROUP BY cluster_id
                 HAVING COUNT(DISTINCT TRIM(person_name)) > 1
                 ORDER BY named_faces DESC, distinct_names DESC
                 LIMIT ?",
                [$limit],
                fn (object $row): array => [
                    'cluster_id' => (int) $row->cluster_id,
                    'distinct_names' => (int) $row->distinct_names,
                    'named_faces' => (int) $row->named_faces,
                    'names' => (string) ($row->names ?? ''),
                ]
            ),
            'linked_display_mismatches' => $this->sampleRows(
                "SELECT f.id AS face_id,
                        f.file_registry_id,
                        f.person_name,
                        f.genealogy_person_id,
                        TRIM(CONCAT_WS(' ', NULLIF(p.given_name, ''), NULLIF(p.surname, ''), NULLIF(p.suffix, ''))) AS canonical_name
                 FROM file_registry_faces f
                 JOIN genealogy_persons p ON p.id = f.genealogy_person_id
                 WHERE f.genealogy_person_id IS NOT NULL
                   AND TRIM(COALESCE(f.person_name, '')) <> ''
                   AND f.person_name <> TRIM(CONCAT_WS(' ', NULLIF(p.given_name, ''), NULLIF(p.surname, ''), NULLIF(p.suffix, '')))
                 ORDER BY f.updated_at ASC, f.id ASC
                 LIMIT ?",
                [$limit],
                fn (object $row): array => [
                    'face_id' => (int) $row->face_id,
                    'file_registry_id' => (int) $row->file_registry_id,
                    'person_name' => (string) $row->person_name,
                    'genealogy_person_id' => (int) $row->genealogy_person_id,
                    'canonical_name' => (string) $row->canonical_name,
                ]
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, int|bool>
     */
    private function summarizeChecks(array $checks): array
    {
        $criticalKeys = [
            'unknown_faces',
            'unknown_clusters',
            'linked_display_mismatches',
            'approved_queue_not_applied',
            'mysql_duplicate_identity_groups',
            'mysql_duplicate_cluster_groups',
            'pg_duplicate_cluster_groups',
        ];

        $criticalFindings = 0;
        foreach ($criticalKeys as $key) {
            $criticalFindings += (int) ($checks[$key] ?? 0);
        }

        return [
            'query_would_run' => true,
            'critical_findings' => $criticalFindings,
            'review_findings' => (int) ($checks['exact_name_unlinked_unqueued'] ?? 0)
                + (int) ($checks['ambiguous_exact_names'] ?? 0)
                + (int) ($checks['stale_merged_cluster_refs'] ?? 0),
            'passes_core_integrity' => $criticalFindings === 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>
     */
    private function buildRepairPlan(array $checks): array
    {
        return [
            'mode' => $this->option('confirm-repair') ? 'write' : 'dry_run',
            'deterministic_only' => true,
            'will_not_auto_link_people' => true,
            'will_not_merge_clusters' => true,
            'actions' => [
                'clear_whitespace_only_face_names' => (int) ($checks['whitespace_only_face_names'] ?? 0),
                'clear_stale_merged_cluster_refs' => (int) ($checks['stale_merged_cluster_refs'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, int>
     */
    private function applySafeRepairs(array $plan): array
    {
        $applied = [
            'whitespace_only_face_names_cleared' => 0,
            'stale_merged_cluster_refs_cleared' => 0,
        ];

        if ((int) ($plan['actions']['clear_whitespace_only_face_names'] ?? 0) > 0) {
            $applied['whitespace_only_face_names_cleared'] = DB::update(
                "UPDATE file_registry_faces
                 SET person_name = '', updated_at = NOW()
                 WHERE person_name IS NOT NULL
                   AND person_name <> ''
                   AND TRIM(person_name) = ''"
            );
        }

        if ((int) ($plan['actions']['clear_stale_merged_cluster_refs'] ?? 0) > 0) {
            $applied['stale_merged_cluster_refs_cleared'] = $this->clearStaleMergedClusterRefs();
        }

        Log::warning('OpsFaceIntegrityAudit: applied safe face repairs', $applied);

        return $applied;
    }

    private function countStaleMergedClusterRefs(): int
    {
        $ids = $this->mergedZeroClusterIds();
        if ($ids === []) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $row = DB::selectOne(
                "SELECT COUNT(*) AS cnt
                 FROM file_registry_faces
                 WHERE cluster_id IN ({$placeholders})",
                $chunk
            );
            $count += (int) ($row->cnt ?? 0);
        }

        return $count;
    }

    private function clearStaleMergedClusterRefs(): int
    {
        $ids = $this->mergedZeroClusterIds();
        if ($ids === []) {
            return 0;
        }

        $updated = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $updated += DB::update(
                "UPDATE file_registry_faces
                 SET cluster_id = NULL, updated_at = NOW()
                 WHERE cluster_id IN ({$placeholders})",
                $chunk
            );
        }

        return $updated;
    }

    /**
     * @return array<int, int>
     */
    private function mergedZeroClusterIds(): array
    {
        return array_map(
            fn (object $row): int => (int) $row->id,
            DB::connection('pgsql_rag')->select(
                "SELECT id
                 FROM person_clusters
                 WHERE status = 'merged'
                   AND COALESCE(face_count, 0) = 0"
            )
        );
    }

    private function exactNameUnlinkedUnqueuedCountSql(): string
    {
        return "SELECT COUNT(*) AS cnt
                FROM file_registry_faces frf
                JOIN (
                    SELECT canonical_name, MIN(id) AS person_id
                    FROM (
                        SELECT id,
                               TRIM(CONCAT_WS(' ', NULLIF(given_name, ''), NULLIF(surname, ''), NULLIF(suffix, ''))) AS canonical_name
                        FROM genealogy_persons
                        WHERE tree_id = 4
                    ) pnames
                    WHERE canonical_name <> ''
                    GROUP BY canonical_name
                    HAVING COUNT(*) = 1
                ) cand ON cand.canonical_name = frf.person_name
                WHERE frf.genealogy_person_id IS NULL
                  AND frf.hidden = 0
                  AND TRIM(COALESCE(frf.person_name, '')) <> ''
                  AND NOT EXISTS (
                    SELECT 1
                    FROM genealogy_face_match_queue q
                    WHERE q.file_registry_face_id = frf.id
                  )";
    }

    private function countOne(string $sql): int
    {
        $row = DB::selectOne($sql);

        return (int) ($row->cnt ?? 0);
    }

    private function countPgOne(string $sql): int
    {
        $row = DB::connection('pgsql_rag')->selectOne($sql);

        return (int) ($row->cnt ?? 0);
    }

    /**
     * @param  array<int, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function sampleRows(string $sql, array $params, callable $mapper): array
    {
        return array_map($mapper, DB::select($sql, $params));
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $limit, bool $repairSafe, bool $confirmRepair): array
    {
        return [
            'command' => 'ops:face-integrity-audit',
            'mode' => $confirmRepair ? 'write' : ($repairSafe ? 'repair_preview' : 'observe'),
            'dry_run' => false,
            'read_only' => ! $confirmRepair,
            'mutation_allowed' => $confirmRepair,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'limit' => $limit,
            ],
            'posture' => [
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'safe_repair_requires_confirm' => true,
                'samples_included' => $limit > 0,
            ],
            'summary' => [
                'query_would_run' => true,
                'critical_findings' => 0,
                'review_findings' => 0,
                'passes_core_integrity' => false,
            ],
            'checks' => [],
            'repair_plan' => null,
            'repairs_applied' => null,
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

        $this->info('Face integrity audit');
        $this->line('Mode: '.$payload['mode']);
        $this->line('Core integrity: '.(($payload['summary']['passes_core_integrity'] ?? false) ? 'pass' : 'review'));
        $this->line('Critical findings: '.(int) ($payload['summary']['critical_findings'] ?? 0));
        $this->line('Review findings: '.(int) ($payload['summary']['review_findings'] ?? 0));
        $this->line('');
        $this->line(json_encode($payload['checks'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
