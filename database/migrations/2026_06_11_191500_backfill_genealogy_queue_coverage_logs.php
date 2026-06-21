<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MARKER = '2026_06_11_191500_backfill_genealogy_queue_coverage_logs';

    public function up(): void
    {
        DB::statement("
            INSERT INTO gps_research_tasks
                (person_id, tree_id, task_type, question, hypothesis, status, priority,
                 conclusion, evidence_summary, resolved_at, created_at, updated_at)
            SELECT
                t.person_id,
                t.tree_id,
                CASE
                    WHEN LOWER(CONCAT(COALESCE(q.question_type, ''), ' ', COALESCE(t.research_question, ''))) LIKE '%birth%' THEN 'birth'
                    WHEN LOWER(CONCAT(COALESCE(q.question_type, ''), ' ', COALESCE(t.research_question, ''))) LIKE '%death%'
                      OR LOWER(COALESCE(t.research_question, '')) LIKE '%probate%' THEN 'death'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%marriage%'
                      OR LOWER(COALESCE(t.research_question, '')) LIKE '%spouse%' THEN 'marriage'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%parent%'
                      OR LOWER(COALESCE(t.research_question, '')) LIKE '%child%' THEN 'parentage'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%identity%'
                      OR LOWER(COALESCE(q.question_type, '')) LIKE '%verify%' THEN 'identity'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%place%'
                      OR LOWER(COALESCE(t.research_question, '')) LIKE '%location%' THEN 'location'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%occupation%' THEN 'occupation'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%migration%'
                      OR LOWER(COALESCE(t.research_question, '')) LIKE '%immigration%' THEN 'migration'
                    WHEN LOWER(COALESCE(t.research_question, '')) LIKE '%military%' THEN 'military'
                    ELSE 'other'
                END,
                COALESCE(NULLIF(t.research_question, ''), CONCAT('Queue-mode genealogy research run for ', q.person_name)),
                CONCAT('Mirrored from genealogy_research_tasks.id=', t.id),
                CASE
                    WHEN t.outcome_state = 'completed' THEN 'resolved'
                    WHEN t.outcome_state = 'requeue' THEN 'in_progress'
                    ELSE 'inconclusive'
                END,
                'high',
                t.outcome_reason,
                JSON_OBJECT(
                    'queue_task_id', t.id,
                    'queue_item_id', t.queue_item_id,
                    'outcome_state', t.outcome_state,
                    'outcome_reason', t.outcome_reason,
                    'sources_checked', COALESCE(t.sources_checked, JSON_ARRAY()),
                    'findings_count', COALESCE(JSON_EXTRACT(t.results, '$.findings_count'), 0),
                    'review_items_count', COALESCE(JSON_EXTRACT(t.results, '$.review_items_count'), 0),
                    'backfill_migration', '".self::MARKER."'
                ),
                CASE WHEN t.outcome_state = 'requeue' THEN NULL ELSE t.completed_at END,
                NOW(),
                NOW()
            FROM genealogy_research_tasks t
            JOIN genealogy_research_queue q ON q.id = t.queue_item_id
            WHERE t.queue_item_id IS NOT NULL
              AND t.person_id IS NOT NULL
              AND t.status = 'completed'
              AND t.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND NOT EXISTS (
                  SELECT 1
                  FROM gps_research_tasks gt
                  WHERE gt.hypothesis = CONCAT('Mirrored from genealogy_research_tasks.id=', t.id)
              )
        ");

        DB::statement("
            INSERT INTO gps_research_logs
                (task_id, person_id, log_type, repository_searched, repository_url,
                 search_terms, date_range_searched, location_searched, record_types_searched,
                 results_summary, negative_result, source_ids_found, media_ids_found,
                 search_duration_minutes, notes, searched_at, created_at)
            SELECT
                gt.id,
                t.person_id,
                'search',
                COALESCE(NULLIF(LEFT(CAST(t.sources_checked AS CHAR), 255), 'null'), 'queue-mode genealogy research'),
                NULL,
                LEFT(TRIM(CONCAT(COALESCE(q.person_name, 'Unknown person'), ' ', COALESCE(t.research_question, ''))), 1000),
                NULL,
                NULL,
                t.sources_checked,
                COALESCE(t.evidence_summary, t.outcome_reason),
                CASE WHEN t.outcome_state = 'deferred' THEN 1 ELSE 0 END,
                NULL,
                NULL,
                CASE
                    WHEN JSON_EXTRACT(t.results, '$.duration_ms') IS NULL THEN NULL
                    ELSE GREATEST(1, CEIL(CAST(JSON_UNQUOTE(JSON_EXTRACT(t.results, '$.duration_ms')) AS UNSIGNED) / 60000))
                END,
                CONCAT('Backfilled from queue research task ', t.id, '; outcome=', COALESCE(t.outcome_state, 'unknown')),
                t.completed_at,
                NOW()
            FROM genealogy_research_tasks t
            JOIN genealogy_research_queue q ON q.id = t.queue_item_id
            JOIN gps_research_tasks gt ON gt.hypothesis = CONCAT('Mirrored from genealogy_research_tasks.id=', t.id)
            WHERE t.queue_item_id IS NOT NULL
              AND t.person_id IS NOT NULL
              AND t.status = 'completed'
              AND t.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND NOT EXISTS (
                  SELECT 1
                  FROM gps_research_logs gl
                  WHERE gl.task_id = gt.id
                    AND gl.notes = CONCAT('Backfilled from queue research task ', t.id, '; outcome=', COALESCE(t.outcome_state, 'unknown'))
              )
        ");

        DB::statement("
            UPDATE genealogy_person_coverage c
            JOIN (
                SELECT
                    gt.tree_id,
                    gl.person_id,
                    MAX(gl.searched_at) AS last_searched_at,
                    SUM(CASE WHEN gl.searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS search_count_30d,
                    SUM(CASE WHEN gl.searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND gl.negative_result = 1 THEN 1 ELSE 0 END) AS negative_count_30d,
                    COUNT(*) AS search_count_90d,
                    SUM(gl.negative_result) AS negative_count_90d
                FROM gps_research_logs gl
                JOIN gps_research_tasks gt ON gt.id = gl.task_id
                WHERE gl.searched_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                  AND gt.tree_id IN (
                      SELECT DISTINCT tree_id
                      FROM genealogy_research_tasks
                      WHERE queue_item_id IS NOT NULL
                        AND status = 'completed'
                        AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  )
                GROUP BY gt.tree_id, gl.person_id
            ) s ON s.tree_id = c.tree_id AND s.person_id = c.person_id
            SET c.last_searched_at = s.last_searched_at,
                c.search_count_30d = LEAST(s.search_count_30d, 65535),
                c.negative_count_30d = LEAST(s.negative_count_30d, 65535),
                c.research_exhaustion_score = ROUND(
                    LEAST(s.negative_count_90d / GREATEST(s.search_count_90d, 1), 1),
                    3
                ),
                c.coverage_updated_at = NOW(),
                c.updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("
            DELETE FROM gps_research_tasks
            WHERE JSON_UNQUOTE(JSON_EXTRACT(evidence_summary, '$.backfill_migration')) = '".self::MARKER."'
        ");
    }
};
