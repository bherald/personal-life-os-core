<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * GPS Research Task Service
 *
 * Implements Genealogical Proof Standard (GPS) methodology for research management.
 * Uses RAW SQL queries - NO Eloquent models per project rules.
 *
 * GPS 5 Elements:
 * 1. Reasonably exhaustive search
 * 2. Complete and accurate source citations
 * 3. Analysis and correlation of evidence
 * 4. Resolution of conflicting evidence
 * 5. Soundly reasoned, coherently written conclusion
 *
 * @see docs/future-enhancements.md Priority 4
 * @see Board for Certification of Genealogists GPS standard
 */
class ResearchTaskService
{
    /**
     * Task types aligned with genealogical research categories
     */
    public const TASK_TYPES = [
        'birth' => 'Birth date/place verification',
        'death' => 'Death date/place verification',
        'marriage' => 'Marriage records and spouse identification',
        'parentage' => 'Parent identification and biological relationships',
        'identity' => 'Person identity confirmation (same person problem)',
        'location' => 'Residence and migration tracking',
        'occupation' => 'Occupation and employment history',
        'migration' => 'Immigration/emigration and movement patterns',
        'military' => 'Military service records',
        'other' => 'Other research question',
    ];

    /**
     * Standard repositories by category for exhaustive search guidance
     */
    public const REPOSITORY_CATEGORIES = [
        'vital_records' => 'Birth, death, marriage certificates',
        'census' => 'Federal and state census records',
        'church' => 'Baptism, marriage, burial records',
        'military' => 'Service records, pensions, draft registrations',
        'immigration' => 'Passenger lists, naturalization',
        'land' => 'Deeds, patents, surveys',
        'probate' => 'Wills, estates, guardianships',
        'newspaper' => 'Obituaries, announcements, articles',
        'cemetery' => 'Gravestone records, burial registers',
        'dna' => 'DNA testing and matching',
        'other' => 'Other repository types',
    ];

    // =========================================================================
    // TASK MANAGEMENT
    // =========================================================================

    /**
     * Create a new GPS research task
     *
     * @param int $personId Person being researched
     * @param string $question The research question
     * @param string|null $hypothesis Working theory/proposed answer
     * @param string $taskType Type of research (birth, death, marriage, etc.)
     * @param array $options Additional options (priority, due_date, assigned_to)
     * @return int New task ID
     * @throws InvalidArgumentException
     */
    public function createTask(
        int $personId,
        string $question,
        ?string $hypothesis = null,
        string $taskType = 'other',
        array $options = []
    ): int {
        // Validate task type
        if (!array_key_exists($taskType, self::TASK_TYPES)) {
            throw new InvalidArgumentException("Invalid task type: {$taskType}");
        }

        // Get tree_id from person
        $person = DB::selectOne("SELECT tree_id, given_name, surname FROM genealogy_persons WHERE id = ?", [$personId]);
        if (!$person) {
            throw new InvalidArgumentException("Person not found: {$personId}");
        }

        $priority = $options['priority'] ?? 'medium';
        $dueDate = $options['due_date'] ?? null;
        $assignedTo = $options['assigned_to'] ?? null;

        $sql = "INSERT INTO gps_research_tasks
                (person_id, tree_id, task_type, question, hypothesis, status, priority, assigned_to, due_date, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $personId,
            $person->tree_id,
            $taskType,
            $question,
            $hypothesis,
            $priority,
            $assignedTo,
            $dueDate,
        ]);

        $taskId = (int) DB::getPdo()->lastInsertId();

        // Create initial GPS assessment
        $this->createInitialAssessment($taskId, $personId);

        Log::info('ResearchTaskService: Task created', [
            'task_id' => $taskId,
            'person_id' => $personId,
            'person_name' => "{$person->given_name} {$person->surname}",
            'task_type' => $taskType,
            'question' => substr($question, 0, 100),
        ]);

        return $taskId;
    }

    /**
     * Get a single task with full details
     */
    public function getTask(int $taskId): ?array
    {
        $sql = "SELECT t.*,
                       p.given_name, p.surname, p.birth_date, p.death_date,
                       tr.name as tree_name
                FROM gps_research_tasks t
                JOIN genealogy_persons p ON p.id = t.person_id
                JOIN genealogy_trees tr ON tr.id = t.tree_id
                WHERE t.id = ?";

        $task = DB::selectOne($sql, [$taskId]);
        if (!$task) {
            return null;
        }

        $result = (array) $task;
        $result['logs'] = $this->getResearchLog($taskId);
        $result['assessment'] = $this->getLatestAssessment($taskId);
        $result['person_name'] = "{$task->given_name} {$task->surname}";

        return $result;
    }

    /**
     * Update task status
     */
    public function updateTaskStatus(int $taskId, string $status, ?string $conclusion = null): bool
    {
        $validStatuses = ['open', 'in_progress', 'resolved', 'inconclusive', 'abandoned'];
        if (!in_array($status, $validStatuses)) {
            throw new InvalidArgumentException("Invalid status: {$status}");
        }

        $resolvedAt = in_array($status, ['resolved', 'inconclusive', 'abandoned']) ? 'NOW()' : 'NULL';

        $sql = "UPDATE gps_research_tasks
                SET status = ?, conclusion = ?, resolved_at = {$resolvedAt}, updated_at = NOW()
                WHERE id = ?";

        $updated = DB::update($sql, [$status, $conclusion, $taskId]) > 0;

        if ($updated) {
            Log::info('ResearchTaskService: Task status updated', [
                'task_id' => $taskId,
                'status' => $status,
            ]);
        }

        return $updated;
    }

    /**
     * Get open tasks for a tree
     */
    public function getOpenTasks(int $treeId, array $filters = []): array
    {
        $sql = "SELECT t.*,
                       p.given_name, p.surname,
                       (SELECT COUNT(*) FROM gps_research_logs WHERE task_id = t.id) as log_count,
                       (SELECT overall_score FROM gps_assessments WHERE task_id = t.id ORDER BY assessed_at DESC LIMIT 1) as gps_score
                FROM gps_research_tasks t
                JOIN genealogy_persons p ON p.id = t.person_id
                WHERE t.tree_id = ? AND t.status IN ('open', 'in_progress')";

        $params = [$treeId];

        if (!empty($filters['task_type'])) {
            $sql .= " AND t.task_type = ?";
            $params[] = $filters['task_type'];
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND t.priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['person_id'])) {
            $sql .= " AND t.person_id = ?";
            $params[] = $filters['person_id'];
        }

        $sql .= " ORDER BY
                    FIELD(t.priority, 'critical', 'high', 'medium', 'low'),
                    t.due_date ASC,
                    t.created_at DESC";

        return DB::select($sql, $params);
    }

    /**
     * Get tasks for a specific person
     */
    public function getPersonTasks(int $personId, bool $includeResolved = false): array
    {
        $sql = "SELECT t.*,
                       (SELECT COUNT(*) FROM gps_research_logs WHERE task_id = t.id) as log_count,
                       (SELECT overall_score FROM gps_assessments WHERE task_id = t.id ORDER BY assessed_at DESC LIMIT 1) as gps_score
                FROM gps_research_tasks t
                WHERE t.person_id = ?";

        if (!$includeResolved) {
            $sql .= " AND t.status IN ('open', 'in_progress')";
        }

        $sql .= " ORDER BY t.created_at DESC";

        return DB::select($sql, [$personId]);
    }

    /**
     * Re-open stale active tasks and abandon older duplicate active tasks.
     */
    public function cleanupActiveTaskBacklog(?int $treeId = null, int $staleHours = 72): array
    {
        $staleTasks = $this->getStaleActiveTasks($treeId, $staleHours);
        $reopened = [];

        foreach ($staleTasks as $task) {
            $affected = DB::update(
                "UPDATE gps_research_tasks
                 SET status = 'open', resolved_at = NULL, updated_at = NOW()
                 WHERE id = ? AND status = 'in_progress' AND conclusion IS NULL",
                [$task['id']]
            );

            if ($affected > 0) {
                $reopened[] = (int) $task['id'];
            }
        }

        $duplicateGroups = $this->findDuplicateActiveTaskGroups($treeId);
        $abandoned = [];

        foreach ($duplicateGroups as $group) {
            $survivor = $this->selectPreferredActiveTask($group);

            foreach ($group as $task) {
                if ((int) $task['id'] === (int) $survivor['id']) {
                    continue;
                }

                $affected = DB::update(
                    "UPDATE gps_research_tasks
                     SET status = 'abandoned',
                         conclusion = ?,
                         resolved_at = NOW(),
                         updated_at = NOW()
                     WHERE id = ? AND status IN ('open', 'in_progress')",
                    ["Superseded by active task #{$survivor['id']} during genealogy backlog cleanup.", $task['id']]
                );

                if ($affected > 0) {
                    $abandoned[] = [
                        'id' => (int) $task['id'],
                        'superseded_by' => (int) $survivor['id'],
                    ];
                }
            }
        }

        Log::info('ResearchTaskService: backlog cleanup complete', [
            'tree_id' => $treeId,
            'stale_hours' => $staleHours,
            'reopened_count' => count($reopened),
            'abandoned_count' => count($abandoned),
        ]);

        return [
            'reopened_count' => count($reopened),
            'abandoned_count' => count($abandoned),
            'reopened_task_ids' => $reopened,
            'abandoned_tasks' => $abandoned,
        ];
    }

    public function getStaleActiveTasks(?int $treeId = null, int $staleHours = 72): array
    {
        $sql = "SELECT t.id, t.person_id, t.tree_id, t.task_type, t.priority, t.question, t.updated_at
                FROM gps_research_tasks t
                WHERE t.status = 'in_progress'
                  AND t.conclusion IS NULL
                  AND t.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$staleHours];

        if ($treeId !== null) {
            $sql .= " AND t.tree_id = ?";
            $params[] = $treeId;
        }

        $sql .= " ORDER BY t.updated_at ASC";

        return array_map(static fn ($row) => (array) $row, DB::select($sql, $params));
    }

    public function getQualityTriageCandidates(int $treeId, int $limit = 5): array
    {
        $rows = DB::select(
            "SELECT t.id, t.person_id, t.tree_id, t.status, t.priority, t.task_type, t.question, t.updated_at,
                    CONCAT_WS(' ', p.given_name, p.surname) AS person_name,
                    COUNT(DISTINCT ps.source_id) AS source_count,
                    COUNT(DISTINCT l.id) AS log_count,
                    MAX(l.created_at) AS last_log_at
             FROM gps_research_tasks t
             JOIN genealogy_persons p ON p.id = t.person_id
             LEFT JOIN genealogy_person_sources ps ON ps.person_id = t.person_id
             LEFT JOIN gps_research_logs l ON l.task_id = t.id
             WHERE t.tree_id = ?
               AND t.status IN ('open', 'in_progress')
               AND t.question IS NOT NULL
             GROUP BY t.id, t.person_id, t.tree_id, t.status, t.priority, t.task_type, t.question, t.updated_at, p.given_name, p.surname
             HAVING source_count > 0 OR log_count > 0
             ORDER BY
               source_count DESC,
               log_count DESC,
               FIELD(t.priority, 'critical', 'high', 'medium', 'low'),
               t.updated_at DESC
             LIMIT ?",
            [$treeId, $limit]
        );

        return array_map(static fn ($row) => (array) $row, $rows);
    }

    public function normalizeTaskEvidenceSources(int $taskId): array
    {
        $task = DB::selectOne(
            "SELECT t.id, t.tree_id, t.person_id, t.question, p.given_name, p.surname
             FROM gps_research_tasks t
             JOIN genealogy_persons p ON p.id = t.person_id
             WHERE t.id = ?",
            [$taskId]
        );

        if (! $task) {
            throw new InvalidArgumentException("Task not found: {$taskId}");
        }

        $logs = $this->getResearchLog($taskId);
        $linked = 0;
        $created = 0;
        $linkedIds = [];

        foreach ($logs as $log) {
            $candidateSourceIds = [];

            foreach ((array) ($log->source_ids_found ?? []) as $sourceId) {
                $sourceId = (int) $sourceId;
                if ($sourceId > 0) {
                    $candidateSourceIds[] = $sourceId;
                }
            }

            if ($candidateSourceIds === [] && ! $log->negative_result) {
                $candidate = $this->buildMinimalSourceFromResearchLog((array) $task, (array) $log);
                if ($candidate !== null) {
                    $sourceId = $this->findOrCreateNormalizedSource((int) $task->tree_id, $candidate);
                    if ($sourceId !== null) {
                        $candidateSourceIds[] = $sourceId;
                        $created++;
                    }
                }
            }

            foreach (array_unique($candidateSourceIds) as $sourceId) {
                $affected = DB::insert(
                    'INSERT IGNORE INTO genealogy_person_sources (person_id, source_id, page, quality, created_at)
                     VALUES (?, ?, ?, ?, NOW())',
                    [(int) $task->person_id, (int) $sourceId, null, 'secondary']
                );

                $linked++;
                $linkedIds[] = (int) $sourceId;
            }
        }

        Log::info('ResearchTaskService: normalized task evidence sources', [
            'task_id' => $taskId,
            'created_sources' => $created,
            'linked_sources' => count(array_unique($linkedIds)),
        ]);

        return [
            'task_id' => $taskId,
            'created_sources' => $created,
            'linked_sources' => count(array_unique($linkedIds)),
            'source_ids' => array_values(array_unique($linkedIds)),
        ];
    }

    private function findDuplicateActiveTaskGroups(?int $treeId = null): array
    {
        $sql = "SELECT t.id, t.person_id, t.tree_id, t.task_type, t.status, t.priority, t.question, t.updated_at,
                       COUNT(DISTINCT ps.source_id) AS source_count,
                       COUNT(DISTINCT l.id) AS log_count
                FROM gps_research_tasks t
                LEFT JOIN genealogy_person_sources ps ON ps.person_id = t.person_id
                LEFT JOIN gps_research_logs l ON l.task_id = t.id
                WHERE t.status IN ('open', 'in_progress')
                  AND t.conclusion IS NULL
                  AND (? IS NULL OR t.tree_id = ?)
                GROUP BY t.id, t.person_id, t.tree_id, t.task_type, t.status, t.priority, t.question, t.updated_at
                ORDER BY t.person_id, t.task_type, t.updated_at DESC";
        $params = [$treeId, $treeId];

        $rows = array_map(static fn ($row) => (array) $row, DB::select($sql, $params));
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['person_id'].'|'.$row['task_type']][] = $row;
        }

        return array_values(array_filter($grouped, static fn ($tasks) => count($tasks) > 1));
    }

    private function selectPreferredActiveTask(array $tasks): array
    {
        usort($tasks, function (array $left, array $right): int {
            $leftScore = [
                $this->priorityRank((string) ($left['priority'] ?? 'low')),
                (int) ($left['source_count'] ?? 0),
                (int) ($left['log_count'] ?? 0),
                strtotime((string) ($left['updated_at'] ?? '1970-01-01')),
            ];
            $rightScore = [
                $this->priorityRank((string) ($right['priority'] ?? 'low')),
                (int) ($right['source_count'] ?? 0),
                (int) ($right['log_count'] ?? 0),
                strtotime((string) ($right['updated_at'] ?? '1970-01-01')),
            ];

            return $rightScore <=> $leftScore;
        });

        return $tasks[0];
    }

    private function priorityRank(string $priority): int
    {
        return match ($priority) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    private function buildMinimalSourceFromResearchLog(array $task, array $log): ?array
    {
        $summary = trim((string) ($log['results_summary'] ?? ''));
        $repository = trim((string) ($log['repository_searched'] ?? ''));

        if (
            $summary === ''
            || preg_match('/^hybrid workflow completed/i', $summary) === 1
            || preg_match('/error executing|missing required parameter/i', $summary) === 1
            || $this->looksLikeRawToolPayload($summary)
            || $this->likelyTargetsDifferentNamedSubject($summary, $task)
        ) {
            return null;
        }

        $title = $repository !== ''
            ? mb_strimwidth($repository.' — '.$summary, 0, 240, '...')
            : mb_strimwidth($summary, 0, 240, '...');

        $url = null;
        if (preg_match('/https?:\/\/\S+/i', $summary, $matches) === 1) {
            $url = rtrim($matches[0], '.,;)');
        } elseif (!empty($log['repository_url'])) {
            $url = trim((string) $log['repository_url']);
        }

        return [
            'title' => $title,
            'author' => null,
            'publication' => $repository !== '' ? $repository : 'Task evidence normalization',
            'repository' => $repository !== '' ? mb_strimwidth($repository, 0, 255, '...') : null,
            'url' => $url,
            'source_quality' => 'derivative',
            'information_quality' => 'secondary',
            'notes' => mb_strimwidth(
                "Normalized from research task #{$task['id']} evidence log.\nQuestion: {$task['question']}\nSummary: {$summary}",
                0,
                4000,
                '...'
            ),
        ];
    }

    private function looksLikeRawToolPayload(string $summary): bool
    {
        $trimmed = ltrim($summary);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^\{/', $trimmed) === 1 || preg_match('/^\[/', $trimmed) === 1) {
            return true;
        }

        return preg_match('/"success"\s*:\s*(true|false)|"query"\s*:|"results"\s*:/i', $summary) === 1;
    }

    private function likelyTargetsDifferentNamedSubject(string $summary, array $task): bool
    {
        $givenName = trim((string) ($task['given_name'] ?? ''));
        $surname = trim((string) ($task['surname'] ?? ''));

        if ($givenName === '' || $surname === '') {
            return false;
        }

        $expectedFullName = $this->normalizePersonName($givenName.' '.$surname);
        $normalizedSummary = $this->normalizePersonName($summary);

        if ($expectedFullName === '' || str_contains($normalizedSummary, $expectedFullName)) {
            return false;
        }

        $surnamePattern = preg_quote($surname, '/');
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2}\s+'.$surnamePattern.')\b/u', $summary, $matches) < 1) {
            return false;
        }

        foreach (($matches[1] ?? []) as $candidate) {
            $candidateName = $this->normalizePersonName((string) $candidate);
            if ($candidateName === '') {
                continue;
            }

            if ($candidateName !== $expectedFullName) {
                return true;
            }
        }

        return false;
    }

    private function normalizePersonName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9\s]+/i', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function findOrCreateNormalizedSource(int $treeId, array $candidate): ?int
    {
        $existing = null;

        if (!empty($candidate['url'])) {
            $existing = DB::selectOne(
                'SELECT id FROM genealogy_sources WHERE tree_id = ? AND url = ? LIMIT 1',
                [$treeId, $candidate['url']]
            );
        }

        if (!$existing) {
            $existing = DB::selectOne(
                'SELECT id FROM genealogy_sources WHERE tree_id = ? AND title = ? LIMIT 1',
                [$treeId, $candidate['title']]
            );
        }

        if ($existing) {
            return (int) $existing->id;
        }

        DB::insert(
            'INSERT INTO genealogy_sources
                (tree_id, title, author, publication, repository, url, source_quality, information_quality, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $treeId,
                $candidate['title'],
                $candidate['author'],
                $candidate['publication'],
                $candidate['repository'],
                $candidate['url'],
                $candidate['source_quality'],
                $candidate['information_quality'],
                $candidate['notes'],
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    // =========================================================================
    // RESEARCH LOGGING
    // =========================================================================

    /**
     * Log a search activity (including negative results)
     *
     * @param int $taskId Research task ID
     * @param array $searchDetails Search parameters and results
     * @return int New log entry ID
     */
    public function logSearch(int $taskId, array $searchDetails): int
    {
        $task = DB::selectOne("SELECT person_id FROM gps_research_tasks WHERE id = ?", [$taskId]);
        if (!$task) {
            throw new InvalidArgumentException("Task not found: {$taskId}");
        }

        $sql = "INSERT INTO gps_research_logs
                (task_id, person_id, log_type, repository_searched, repository_url,
                 search_terms, date_range_searched, location_searched, record_types_searched,
                 results_summary, negative_result, source_ids_found, media_ids_found,
                 search_duration_minutes, notes, searched_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $logType = $searchDetails['log_type'] ?? 'search';
        $negativeResult = (bool) ($searchDetails['negative_result'] ?? false);
        $sourceIds = isset($searchDetails['source_ids_found']) ? json_encode($searchDetails['source_ids_found']) : null;
        $mediaIds = isset($searchDetails['media_ids_found']) ? json_encode($searchDetails['media_ids_found']) : null;
        $recordTypes = isset($searchDetails['record_types_searched']) ? json_encode($searchDetails['record_types_searched']) : null;
        $searchedAt = $searchDetails['searched_at'] ?? date('Y-m-d H:i:s');

        DB::insert($sql, [
            $taskId,
            $task->person_id,
            $logType,
            $searchDetails['repository_searched'] ?? null,
            $searchDetails['repository_url'] ?? null,
            $searchDetails['search_terms'] ?? null,
            $searchDetails['date_range_searched'] ?? null,
            $searchDetails['location_searched'] ?? null,
            $recordTypes,
            $searchDetails['results_summary'] ?? null,
            $negativeResult ? 1 : 0,
            $sourceIds,
            $mediaIds,
            $searchDetails['search_duration_minutes'] ?? null,
            $searchDetails['notes'] ?? null,
            $searchedAt,
        ]);

        $logId = (int) DB::getPdo()->lastInsertId();

        // Update task status to in_progress if it was open
        DB::update(
            "UPDATE gps_research_tasks SET status = 'in_progress', updated_at = NOW()
             WHERE id = ? AND status = 'open'",
            [$taskId]
        );

        Log::info('ResearchTaskService: Search logged', [
            'log_id' => $logId,
            'task_id' => $taskId,
            'repository' => $searchDetails['repository_searched'] ?? 'unknown',
            'negative_result' => $negativeResult,
        ]);

        return $logId;
    }

    /**
     * Get research log entries for a task
     */
    public function getResearchLog(int $taskId): array
    {
        $sql = "SELECT l.*
                FROM gps_research_logs l
                WHERE l.task_id = ?
                ORDER BY l.searched_at DESC, l.created_at DESC";

        $logs = DB::select($sql, [$taskId]);

        // Decode JSON fields
        foreach ($logs as $log) {
            if ($log->source_ids_found) {
                $log->source_ids_found = json_decode($log->source_ids_found, true);
            }
            if ($log->media_ids_found) {
                $log->media_ids_found = json_decode($log->media_ids_found, true);
            }
            if ($log->record_types_searched) {
                $log->record_types_searched = json_decode($log->record_types_searched, true);
            }
        }

        return $logs;
    }

    /**
     * Get negative results (important for GPS compliance)
     */
    public function getNegativeResults(int $taskId): array
    {
        $sql = "SELECT repository_searched, search_terms, date_range_searched,
                       location_searched, results_summary, searched_at
                FROM gps_research_logs
                WHERE task_id = ? AND negative_result = 1
                ORDER BY searched_at DESC";

        return DB::select($sql, [$taskId]);
    }

    // =========================================================================
    // GPS ASSESSMENT
    // =========================================================================

    /**
     * Create initial GPS assessment for a task
     */
    private function createInitialAssessment(int $taskId, int $personId): int
    {
        $sql = "INSERT INTO gps_assessments
                (task_id, person_id, exhaustive_search_score, source_citations_complete,
                 evidence_analysis_complete, conflicting_evidence_resolved, sound_conclusion,
                 overall_score, gps_compliant, created_at, updated_at)
                VALUES (?, ?, 0, FALSE, FALSE, FALSE, FALSE, 0, FALSE, NOW(), NOW())";

        DB::insert($sql, [$taskId, $personId]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Assess GPS compliance for a task
     *
     * Returns detailed scoring for all 5 GPS elements.
     *
     * @param int $taskId Task to assess
     * @return array GPS assessment with element scores and recommendations
     */
    public function assessGPSCompliance(int $taskId): array
    {
        $task = $this->getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException("Task not found: {$taskId}");
        }

        $logs = $this->getResearchLog($taskId);

        // Element 1: Reasonably Exhaustive Search
        $element1 = $this->assessExhaustiveSearch($taskId, $logs, $task['task_type']);

        // Element 2: Complete Source Citations
        $element2 = $this->assessSourceCitations($taskId, $logs);

        // Element 3: Evidence Analysis
        $element3 = $this->assessEvidenceAnalysis($taskId, $logs);

        // Element 4: Conflicting Evidence Resolution
        $element4 = $this->assessConflictResolution($taskId);

        // Element 5: Sound Written Conclusion
        $element5 = $this->assessWrittenConclusion($task);

        // Calculate overall score
        $overallScore = $this->calculateOverallScore($element1, $element2, $element3, $element4, $element5);
        $gpsCompliant = $this->isGPSCompliant($element1, $element2, $element3, $element4, $element5);

        // Update or create assessment record
        $this->saveAssessment($taskId, $task['person_id'], [
            'element1' => $element1,
            'element2' => $element2,
            'element3' => $element3,
            'element4' => $element4,
            'element5' => $element5,
            'overall_score' => $overallScore,
            'gps_compliant' => $gpsCompliant,
        ]);

        return [
            'task_id' => $taskId,
            'overall_score' => $overallScore,
            'gps_compliant' => $gpsCompliant,
            'elements' => [
                1 => $element1,
                2 => $element2,
                3 => $element3,
                4 => $element4,
                5 => $element5,
            ],
            'recommendations' => $this->generateRecommendations($element1, $element2, $element3, $element4, $element5),
        ];
    }

    /**
     * Get the latest GPS assessment for a task
     */
    public function getLatestAssessment(int $taskId): ?object
    {
        $sql = "SELECT * FROM gps_assessments
                WHERE task_id = ?
                ORDER BY assessed_at DESC, created_at DESC
                LIMIT 1";

        $assessment = DB::selectOne($sql, [$taskId]);

        if ($assessment) {
            // Decode JSON fields
            if ($assessment->repositories_checked) {
                $assessment->repositories_checked = json_decode($assessment->repositories_checked, true);
            }
            if ($assessment->repositories_remaining) {
                $assessment->repositories_remaining = json_decode($assessment->repositories_remaining, true);
            }
            if ($assessment->citation_issues) {
                $assessment->citation_issues = json_decode($assessment->citation_issues, true);
            }
        }

        return $assessment;
    }

    // =========================================================================
    // GPS ELEMENT ASSESSMENT METHODS
    // =========================================================================

    /**
     * Element 1: Assess exhaustive search coverage
     */
    private function assessExhaustiveSearch(int $taskId, array $logs, string $taskType): array
    {
        // Get recommended repositories for this task type
        $recommended = $this->getRecommendedRepositories($taskType);
        $searched = [];
        $negativeCount = 0;

        foreach ($logs as $log) {
            if ($log->log_type === 'search' && $log->repository_searched) {
                $searched[] = $log->repository_searched;
                if ($log->negative_result) {
                    $negativeCount++;
                }
            }
        }

        $searched = array_unique($searched);
        $searchedCount = count($searched);
        $recommendedCount = count($recommended);

        // Calculate coverage percentage
        $matchedRepos = 0;
        foreach ($recommended as $repo) {
            $repoName = is_object($repo) ? $repo->name : $repo['name'];
            foreach ($searched as $searchedRepo) {
                if (stripos($searchedRepo, $repoName) !== false ||
                    stripos($repoName, $searchedRepo) !== false) {
                    $matchedRepos++;
                    break;
                }
            }
        }

        $coverage = $recommendedCount > 0 ? round(($matchedRepos / $recommendedCount) * 100) : 0;

        // Bonus points for documenting negative results
        $negativeBonus = min(10, $negativeCount * 2);
        $score = min(100, $coverage + $negativeBonus);

        $remaining = array_filter($recommended, function ($repo) use ($searched) {
            $repoName = is_object($repo) ? $repo->name : $repo['name'];
            foreach ($searched as $s) {
                if (stripos($s, $repoName) !== false || stripos($repoName, $s) !== false) {
                    return false;
                }
            }
            return true;
        });

        return [
            'score' => $score,
            'passed' => $score >= 70,
            'repositories_checked' => $searched,
            'repositories_remaining' => array_values($remaining),
            'negative_results_documented' => $negativeCount,
            'notes' => $score >= 70
                ? "Good coverage of relevant repositories"
                : "Additional repositories should be searched: " . implode(', ', array_map(
                    fn($r) => is_object($r) ? $r->name : $r['name'],
                    array_slice($remaining, 0, 3)
                )),
        ];
    }

    /**
     * Element 2: Assess source citation completeness
     */
    private function assessSourceCitations(int $taskId, array $logs): array
    {
        $sourceIds = [];
        foreach ($logs as $log) {
            if (!empty($log->source_ids_found)) {
                $sourceIds = array_merge($sourceIds, $log->source_ids_found);
            }
        }

        $sourceIds = array_unique($sourceIds);
        $sourceCount = count($sourceIds);

        // Check citation quality for found sources
        $issues = [];
        $qualityScore = 100;

        if ($sourceCount > 0) {
            $placeholders = implode(',', array_fill(0, $sourceCount, '?'));
            $sources = DB::select(
                "SELECT id, title, author, publication FROM genealogy_sources WHERE id IN ({$placeholders})",
                $sourceIds
            );

            foreach ($sources as $source) {
                if (empty($source->title)) {
                    $issues[] = "Source #{$source->id} missing title";
                    $qualityScore -= 10;
                }
                if (empty($source->author) && empty($source->publication)) {
                    $issues[] = "Source #{$source->id} missing author/publication info";
                    $qualityScore -= 5;
                }
            }
        } else {
            $qualityScore = 0;
            $issues[] = "No sources have been linked to research findings";
        }

        $qualityScore = max(0, $qualityScore);

        return [
            'score' => $qualityScore,
            'passed' => $qualityScore >= 80 && empty($issues),
            'sources_found' => $sourceCount,
            'issues' => $issues,
            'notes' => empty($issues)
                ? "All source citations appear complete"
                : "Citation improvements needed: " . count($issues) . " issues found",
        ];
    }

    /**
     * Element 3: Assess evidence analysis
     */
    private function assessEvidenceAnalysis(int $taskId, array $logs): array
    {
        // Count evidence types from analysis logs
        $analysisLogs = array_filter($logs, fn($l) => $l->log_type === 'analysis');
        $directCount = 0;
        $indirectCount = 0;
        $negativeCount = 0;

        foreach ($logs as $log) {
            if ($log->negative_result) {
                $negativeCount++;
            }
            // Check results_summary for evidence type indicators
            if ($log->results_summary) {
                $summary = strtolower($log->results_summary);
                if (strpos($summary, 'direct evidence') !== false || strpos($summary, 'primary source') !== false) {
                    $directCount++;
                }
                if (strpos($summary, 'indirect') !== false || strpos($summary, 'secondary') !== false) {
                    $indirectCount++;
                }
            }
        }

        $totalEvidence = $directCount + $indirectCount + $negativeCount;
        $hasAnalysis = count($analysisLogs) > 0;

        // Score based on evidence diversity and analysis
        $score = 0;
        if ($directCount > 0) $score += 40;
        if ($indirectCount > 0) $score += 20;
        if ($negativeCount > 0) $score += 20; // Negative evidence is important!
        if ($hasAnalysis) $score += 20;

        return [
            'score' => min(100, $score),
            'passed' => $score >= 60,
            'direct_evidence_count' => $directCount,
            'indirect_evidence_count' => $indirectCount,
            'negative_evidence_count' => $negativeCount,
            'has_correlation_analysis' => $hasAnalysis,
            'notes' => $score >= 60
                ? "Evidence analysis shows good coverage of evidence types"
                : "More evidence analysis needed - consider documenting evidence types and correlations",
        ];
    }

    /**
     * Element 4: Assess conflict resolution
     */
    private function assessConflictResolution(int $taskId): array
    {
        // Get task to check evidence_summary
        $task = DB::selectOne("SELECT evidence_summary, conclusion FROM gps_research_tasks WHERE id = ?", [$taskId]);

        $conflictsExist = false;
        $conflictsResolved = true;

        if ($task && $task->evidence_summary) {
            $summary = json_decode($task->evidence_summary, true);
            if (isset($summary['conflicts']) && !empty($summary['conflicts'])) {
                $conflictsExist = true;
                $conflictsResolved = isset($summary['conflicts_resolved']) && $summary['conflicts_resolved'];
            }
        }

        // If no conflicts documented, check if conclusion acknowledges potential conflicts
        $score = 100;
        $notes = "No conflicting evidence identified";

        if ($conflictsExist && !$conflictsResolved) {
            $score = 30;
            $notes = "Conflicting evidence exists but has not been resolved";
        } elseif ($conflictsExist && $conflictsResolved) {
            $score = 100;
            $notes = "Conflicting evidence has been resolved";
        }

        return [
            'score' => $score,
            'passed' => !$conflictsExist || $conflictsResolved,
            'conflicts_exist' => $conflictsExist,
            'conflicts_resolved' => $conflictsResolved,
            'notes' => $notes,
        ];
    }

    /**
     * Element 5: Assess written conclusion
     */
    private function assessWrittenConclusion(array $task): array
    {
        $conclusion = $task['conclusion'] ?? null;

        if (empty($conclusion)) {
            return [
                'score' => 0,
                'passed' => false,
                'has_conclusion' => false,
                'word_count' => 0,
                'notes' => "No written conclusion has been documented",
            ];
        }

        $wordCount = str_word_count($conclusion);
        $hasReasoning = preg_match('/because|therefore|evidence|shows|indicates|suggests|based on/i', $conclusion);

        $score = 0;
        if ($wordCount >= 50) $score += 30;
        if ($wordCount >= 100) $score += 20;
        if ($hasReasoning) $score += 50;

        return [
            'score' => min(100, $score),
            'passed' => $score >= 70,
            'has_conclusion' => true,
            'word_count' => $wordCount,
            'has_reasoning' => (bool) $hasReasoning,
            'notes' => $score >= 70
                ? "Conclusion appears well-reasoned"
                : "Conclusion could be strengthened with more detailed reasoning",
        ];
    }

    /**
     * Calculate overall GPS score
     */
    private function calculateOverallScore(array $e1, array $e2, array $e3, array $e4, array $e5): int
    {
        // Weight: Element 1 and 5 are most critical
        return (int) round(
            ($e1['score'] * 0.25) +
            ($e2['score'] * 0.15) +
            ($e3['score'] * 0.20) +
            ($e4['score'] * 0.15) +
            ($e5['score'] * 0.25)
        );
    }

    /**
     * Determine if research meets GPS standard
     */
    private function isGPSCompliant(array $e1, array $e2, array $e3, array $e4, array $e5): bool
    {
        // All elements must pass their individual thresholds
        return $e1['passed'] && $e2['passed'] && $e3['passed'] && $e4['passed'] && $e5['passed'];
    }

    /**
     * Generate recommendations for improving GPS compliance
     */
    private function generateRecommendations(array $e1, array $e2, array $e3, array $e4, array $e5): array
    {
        $recommendations = [];

        if (!$e1['passed']) {
            $remainingRepos = $e1['repositories_remaining'] ?? [];
            $repoNames = array_map(fn($r) => is_object($r) ? $r->name : ($r['name'] ?? 'Unknown'), array_slice($remainingRepos, 0, 3));
            $recommendations[] = [
                'element' => 1,
                'priority' => 'high',
                'action' => 'Expand search to additional repositories',
                'details' => 'Check: ' . implode(', ', $repoNames),
            ];
        }

        if (!$e2['passed']) {
            $recommendations[] = [
                'element' => 2,
                'priority' => 'high',
                'action' => 'Complete source citations',
                'details' => implode('; ', $e2['issues'] ?? ['Add complete citation information']),
            ];
        }

        if (!$e3['passed']) {
            $recommendations[] = [
                'element' => 3,
                'priority' => 'medium',
                'action' => 'Analyze and correlate evidence',
                'details' => 'Document evidence types (direct/indirect/negative) and how they support the conclusion',
            ];
        }

        if (!$e4['passed']) {
            $recommendations[] = [
                'element' => 4,
                'priority' => 'high',
                'action' => 'Resolve conflicting evidence',
                'details' => 'Document how conflicts were resolved or why certain evidence was given more weight',
            ];
        }

        if (!$e5['passed']) {
            $recommendations[] = [
                'element' => 5,
                'priority' => 'high',
                'action' => 'Write a reasoned conclusion',
                'details' => 'Include reasoning that ties evidence to the conclusion with explicit "because/therefore" logic',
            ];
        }

        return $recommendations;
    }

    /**
     * Save or update GPS assessment
     */
    private function saveAssessment(int $taskId, int $personId, array $data): void
    {
        $sql = "INSERT INTO gps_assessments
                (task_id, person_id, exhaustive_search_score, exhaustive_search_notes,
                 repositories_checked, repositories_remaining,
                 source_citations_complete, citation_issues,
                 evidence_analysis_complete, direct_evidence_count, indirect_evidence_count, negative_evidence_count,
                 conflicting_evidence_exists, conflicting_evidence_resolved,
                 sound_conclusion, conclusion_reasoning,
                 overall_score, gps_compliant, assessed_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    exhaustive_search_score = VALUES(exhaustive_search_score),
                    exhaustive_search_notes = VALUES(exhaustive_search_notes),
                    repositories_checked = VALUES(repositories_checked),
                    repositories_remaining = VALUES(repositories_remaining),
                    source_citations_complete = VALUES(source_citations_complete),
                    citation_issues = VALUES(citation_issues),
                    evidence_analysis_complete = VALUES(evidence_analysis_complete),
                    direct_evidence_count = VALUES(direct_evidence_count),
                    indirect_evidence_count = VALUES(indirect_evidence_count),
                    negative_evidence_count = VALUES(negative_evidence_count),
                    conflicting_evidence_exists = VALUES(conflicting_evidence_exists),
                    conflicting_evidence_resolved = VALUES(conflicting_evidence_resolved),
                    sound_conclusion = VALUES(sound_conclusion),
                    conclusion_reasoning = VALUES(conclusion_reasoning),
                    overall_score = VALUES(overall_score),
                    gps_compliant = VALUES(gps_compliant),
                    assessed_at = NOW(),
                    updated_at = NOW()";

        $e1 = $data['element1'];
        $e2 = $data['element2'];
        $e3 = $data['element3'];
        $e4 = $data['element4'];
        $e5 = $data['element5'];

        DB::insert($sql, [
            $taskId,
            $personId,
            $e1['score'],
            $e1['notes'],
            json_encode($e1['repositories_checked'] ?? []),
            json_encode($e1['repositories_remaining'] ?? []),
            $e2['passed'] ? 1 : 0,
            json_encode($e2['issues'] ?? []),
            $e3['passed'] ? 1 : 0,
            $e3['direct_evidence_count'] ?? 0,
            $e3['indirect_evidence_count'] ?? 0,
            $e3['negative_evidence_count'] ?? 0,
            $e4['conflicts_exist'] ? 1 : 0,
            $e4['conflicts_resolved'] ? 1 : 0,
            $e5['passed'] ? 1 : 0,
            $e5['notes'],
            $data['overall_score'],
            $data['gps_compliant'] ? 1 : 0,
        ]);
    }

    // =========================================================================
    // REPOSITORY RECOMMENDATIONS
    // =========================================================================

    /**
     * Get recommended repositories for a task type
     */
    public function getRecommendedRepositories(string $taskType): array
    {
        $categoryMap = [
            'birth' => ['vital_records', 'church', 'census'],
            'death' => ['vital_records', 'cemetery', 'probate', 'newspaper'],
            'marriage' => ['vital_records', 'church', 'newspaper'],
            'parentage' => ['vital_records', 'census', 'dna', 'church'],
            'identity' => ['census', 'vital_records', 'military', 'immigration'],
            'location' => ['census', 'land', 'newspaper'],
            'occupation' => ['census', 'military', 'newspaper'],
            'migration' => ['immigration', 'census', 'land'],
            'military' => ['military'],
            'other' => ['census', 'vital_records'],
        ];

        $categories = $categoryMap[$taskType] ?? $categoryMap['other'];
        $placeholders = implode(',', array_fill(0, count($categories), '?'));

        $sql = "SELECT name, url, category, is_free
                FROM gps_standard_repositories
                WHERE category IN ({$placeholders})
                ORDER BY is_free DESC, name ASC";

        return DB::select($sql, $categories);
    }

    /**
     * Get all standard repositories
     */
    public function getStandardRepositories(?string $category = null): array
    {
        $sql = "SELECT * FROM gps_standard_repositories";
        $params = [];

        if ($category) {
            $sql .= " WHERE category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY category, name";

        return DB::select($sql, $params);
    }

    // =========================================================================
    // STATISTICS & REPORTS
    // =========================================================================

    /**
     * Get GPS compliance statistics for a tree
     */
    public function getTreeStatistics(int $treeId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tasks,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tasks,
                SUM(CASE WHEN status = 'inconclusive' THEN 1 ELSE 0 END) as inconclusive_tasks
            FROM gps_research_tasks
            WHERE tree_id = ?
        ", [$treeId]);

        $gpsStats = DB::selectOne("
            SELECT
                COUNT(*) as assessed_tasks,
                SUM(CASE WHEN gps_compliant = 1 THEN 1 ELSE 0 END) as compliant_tasks,
                AVG(overall_score) as avg_gps_score
            FROM gps_assessments a
            JOIN gps_research_tasks t ON t.id = a.task_id
            WHERE t.tree_id = ?
        ", [$treeId]);

        return [
            'total_tasks' => (int) $stats->total_tasks,
            'open_tasks' => (int) $stats->open_tasks,
            'in_progress_tasks' => (int) $stats->in_progress_tasks,
            'resolved_tasks' => (int) $stats->resolved_tasks,
            'inconclusive_tasks' => (int) $stats->inconclusive_tasks,
            'assessed_tasks' => (int) $gpsStats->assessed_tasks,
            'gps_compliant_tasks' => (int) $gpsStats->compliant_tasks,
            'average_gps_score' => round((float) $gpsStats->avg_gps_score, 1),
        ];
    }

    /**
     * Get recent searches for a tree or specific person.
     *
     * Returns what was already searched so the agent can avoid repeating
     * the same searches on the next run. Grouped by person with search
     * details, repositories searched, and whether results were found.
     *
     * @param int $treeId
     * @param int|null $personId Filter to a specific person (null = all persons in tree)
     * @param int $days How many days back to look (default 30)
     * @param int $limit Max results (default 100)
     */
    public function getRecentSearches(int $treeId, ?int $personId = null, int $days = 30, int $limit = 100): array
    {
        $sql = "SELECT
                    l.id,
                    l.person_id,
                    CONCAT(p.given_name, ' ', p.surname) AS person_name,
                    l.repository_searched,
                    l.search_terms,
                    l.date_range_searched,
                    l.location_searched,
                    l.negative_result,
                    l.results_summary,
                    l.notes,
                    l.searched_at,
                    t.task_type
                FROM gps_research_logs l
                JOIN gps_research_tasks t ON t.id = l.task_id
                JOIN genealogy_persons p ON p.id = l.person_id
                WHERE t.tree_id = ?
                  AND l.searched_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $params = [$treeId, $days];

        if ($personId !== null) {
            $sql .= " AND l.person_id = ?";
            $params[] = $personId;
        }

        $sql .= " ORDER BY l.searched_at DESC LIMIT ?";
        $params[] = $limit;

        $rows = DB::select($sql, $params);

        // Group by person for easier consumption
        $byPerson = [];
        foreach ($rows as $row) {
            $key = $row->person_id;
            if (!isset($byPerson[$key])) {
                $byPerson[$key] = [
                    'person_id' => $row->person_id,
                    'person_name' => $row->person_name,
                    'searches' => [],
                ];
            }
            $byPerson[$key]['searches'][] = [
                'repository' => $row->repository_searched,
                'terms' => $row->search_terms,
                'date_range' => $row->date_range_searched,
                'location' => $row->location_searched,
                'negative_result' => (bool) $row->negative_result,
                'summary' => $row->results_summary,
                'searched_at' => $row->searched_at,
            ];
        }

        return [
            'tree_id' => $treeId,
            'days_back' => $days,
            'total_searches' => count($rows),
            'persons_searched' => count($byPerson),
            'by_person' => array_values($byPerson),
        ];
    }
}
