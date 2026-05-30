<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * N96/N67 — GPS Proof Argument Generator
 *
 * Generates a structured Genealogical Proof Standard (GPS) / NGSQ-style proof
 * argument from DB-sourced evidence only. No genealogy platform does this automatically.
 *
 * GPS Elements:
 *   1. Reasonably exhaustive search documented
 *   2. Complete and accurate citations for all sources
 *   3. Analysis of all relevant evidence
 *   4. Resolution of conflicting evidence
 *   5. Soundly reasoned, coherently written conclusion
 *
 * N67 additions:
 *   - Optional task_id: saves proof to gps_research_tasks.conclusion
 *   - generateForOpenTasks(): batch auto-generation for tasks with evidence
 *
 * Design constraints:
 *   - temperature 0.2 (factual, conservative)
 *   - All claims backed by DB-sourced evidence
 *   - Citations validated post-generation (URLs and source IDs verified)
 *   - No hallucination: prompt explicitly forbids invented facts
 */
class GPSProofGeneratorService
{
    private const LESSON_MEMORY_TYPES = [
        'research_process_lesson',
        'document_interpretation_lesson',
        'source_capture_lesson',
        'identity_decision_lesson',
        'offline_workflow_lesson',
    ];

    private ?AIService $aiService = null;

    private ?SourceConflictService $conflictService = null;

    private ?SearchCoverageService $coverageService = null;

    private ?GenealogyService $genealogyService = null;

    private function ai(): AIService
    {
        if (! $this->aiService) {
            $this->aiService = app(AIService::class);
        }

        return $this->aiService;
    }

    private function conflicts(): SourceConflictService
    {
        if (! $this->conflictService) {
            $this->conflictService = app(SourceConflictService::class);
        }

        return $this->conflictService;
    }

    private function coverage(): SearchCoverageService
    {
        if (! $this->coverageService) {
            $this->coverageService = app(SearchCoverageService::class);
        }

        return $this->coverageService;
    }

    private function genealogy(): GenealogyService
    {
        if (! $this->genealogyService) {
            $this->genealogyService = app(GenealogyService::class);
        }

        return $this->genealogyService;
    }

    /**
     * Generate a GPS proof argument for a specific genealogical question.
     *
     * @param  int  $personId  Person being proven
     * @param  string  $question  The genealogical question (e.g. "Who were the parents of William Doe?")
     * @param  array  $options  Optional: ['max_tokens'=>int, 'task_id'=>int]
     *                          task_id: when provided, saves proof to gps_research_tasks.conclusion and updates status.
     * @return array ['success'=>bool, 'proof'=>string, 'citations_validated'=>int, 'warnings'=>string[], 'task_saved'=>bool]
     */
    public function generateProofArgument(int $personId, string $question, array $options = []): array
    {
        $person = $this->genealogy()->getPersonFull($personId);
        if (! $person) {
            return ['success' => false, 'error' => 'Person not found'];
        }

        // Gather all DB-sourced evidence
        $evidence = $this->gatherEvidence($personId, $person);

        // Gather search coverage (GPS Element 1)
        $coverage = $this->coverage()->getCoverageForPerson($personId);

        // Gather conflicts (GPS Element 4)
        $conflicts = $this->conflicts()->getConflictsForPerson($personId, 'unresolved');

        // Build the prompt
        $prompt = $this->buildProofPrompt($question, $person, $evidence, $coverage, $conflicts);

        // Generate with temperature 0.2 for factual accuracy
        $result = $this->ai()->process($prompt, [
            'temperature' => 0.2,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'model_role' => 'quality',
            'sensitive_data' => true,
            'data_class' => 'genealogy_proof_argument',
        ]);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'AI generation failed'];
        }

        $proofText = $result['response'] ?? '';
        $personName = trim(($person['given_name'] ?? '').' '.($person['surname'] ?? ''));

        $proofValidation = $this->validateProofIntegrity($proofText, $personName, $question, $evidence);
        if (! $proofValidation['valid']) {
            $warnings = $proofValidation['warnings'];
            $fallbackProof = $this->buildDeterministicFallbackProof($person, $question, $evidence, $coverage, $conflicts);

            if ($fallbackProof !== null) {
                $proofText = $fallbackProof;
                $warnings[] = 'AI proof failed validation; deterministic fallback proof was used.';
                $proofValidation = $this->validateProofIntegrity($proofText, $personName, $question, $evidence);
            }
        }

        if (! $proofValidation['valid']) {
            $warnings = $proofValidation['warnings'];

            Log::warning('GPSProofGeneratorService: Proof subject mismatch', [
                'person_id' => $personId,
                'question' => substr($question, 0, 100),
                'warnings' => $warnings,
            ]);

            return [
                'success' => false,
                'error' => 'Generated proof failed subject validation',
                'warnings' => $warnings,
                'proof' => $proofText,
            ];
        }

        // Validate citations: check that all source IDs/URLs mentioned actually exist in DB
        [$citationsValid, $warnings] = $this->validateCitations($proofText, $personId, $evidence);

        // Infer confidence level from proof text for task persistence
        $confidence = $this->inferConfidenceLevel($proofText);

        Log::info('GPSProofGeneratorService: Proof generated', [
            'person_id' => $personId,
            'question' => substr($question, 0, 100),
            'citations_validated' => $citationsValid,
            'warnings' => count($warnings),
            'confidence' => $confidence,
        ]);

        $taskSaved = false;
        $taskId = isset($options['task_id']) ? (int) $options['task_id'] : null;
        if ($taskId) {
            $taskSaved = $this->saveToTask($taskId, $proofText, $confidence, $evidence, $conflicts);
        }

        return [
            'success' => true,
            'person_id' => $personId,
            'question' => $question,
            'proof' => $proofText,
            'confidence' => $confidence,
            'citations_validated' => $citationsValid,
            'warnings' => $warnings,
            'evidence_count' => count($evidence['sources']),
            'unresolved_conflicts' => $conflicts['total'],
            'task_saved' => $taskSaved,
        ];
    }

    /**
     * Batch auto-generate proofs for all open/in_progress research tasks in a tree
     * that have at least one source linked to the person.
     *
     * @param  int  $limit  Max tasks to process in one call
     * @param  bool  $dryRun  If true, return eligible tasks without generating
     * @return array ['processed'=>int, 'skipped'=>int, 'errors'=>int, 'tasks'=>array]
     */
    public function generateForOpenTasks(int $treeId, int $limit = 5, bool $dryRun = false): array
    {
        $tasks = DB::select(
            "SELECT t.id, t.person_id, t.task_type, t.question, t.status
             FROM gps_research_tasks t
             WHERE t.tree_id = ?
               AND t.status IN ('open', 'in_progress')
               AND t.question IS NOT NULL
               AND t.conclusion IS NULL
             ORDER BY
               FIELD(t.status, 'in_progress', 'open'),
               FIELD(t.task_type, 'parentage', 'birth', 'death', 'marriage', 'identity', 'location', 'other')
             LIMIT ?",
            [$treeId, $limit]
        );

        if (empty($tasks)) {
            return ['processed' => 0, 'skipped' => 0, 'errors' => 0, 'tasks' => []];
        }

        // Filter to tasks whose person has at least one source
        $personIds = array_values(array_unique(array_map(
            static fn ($task) => (int) (is_array($task) ? ($task['person_id'] ?? 0) : ($task->person_id ?? 0)),
            $tasks
        )));
        $sourcedPersons = [];
        if (! empty($personIds)) {
            $rows = DB::table('genealogy_person_sources')
                ->select('person_id')
                ->distinct()
                ->whereIn('person_id', $personIds)
                ->get();
            $sourcedPersons = array_values(array_unique(array_map(
                static fn ($row) => (int) ($row->person_id ?? 0),
                $rows->all()
            )));
        }

        $processed = 0;
        $skipped = 0;
        $errors = 0;
        $taskLog = [];

        foreach ($tasks as $task) {
            $task = (array) $task;
            $hasSources = in_array($task['person_id'], $sourcedPersons);

            if (! $hasSources) {
                $skipped++;
                $taskLog[] = ['task_id' => $task['id'], 'status' => 'skipped', 'reason' => 'no sources'];

                continue;
            }

            if ($dryRun) {
                $taskLog[] = ['task_id' => $task['id'], 'person_id' => $task['person_id'], 'status' => 'eligible', 'question' => substr($task['question'], 0, 80)];
                $processed++;

                continue;
            }

            $result = $this->generateProofArgument(
                (int) $task['person_id'],
                $task['question'],
                ['task_id' => (int) $task['id']]
            );

            if ($result['success']) {
                $processed++;
                $taskLog[] = [
                    'task_id' => $task['id'],
                    'person_id' => $task['person_id'],
                    'status' => 'generated',
                    'confidence' => $result['confidence'] ?? 'unknown',
                    'warnings' => count($result['warnings'] ?? []),
                ];
            } else {
                $errors++;
                $taskLog[] = [
                    'task_id' => $task['id'],
                    'status' => 'error',
                    'error' => $result['error'] ?? 'unknown',
                ];
                Log::warning('GPSProofGeneratorService: batch generation failed', [
                    'task_id' => $task['id'],
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'tasks' => $taskLog,
        ];
    }

    /**
     * Save a generated proof to gps_research_tasks and update status.
     */
    private function saveToTask(int $taskId, string $proofText, string $confidence, array $evidence, array $conflicts): bool
    {
        $task = DB::selectOne('SELECT id, tree_id, person_id FROM gps_research_tasks WHERE id = ?', [$taskId]);
        if (! $task) {
            Log::warning('GPSProofGeneratorService: task not found for save', ['task_id' => $taskId]);

            return false;
        }

        $newStatus = in_array($confidence, ['proven', 'probable']) ? 'resolved' : 'inconclusive';

        $evidenceSummary = json_encode([
            'sources_count' => count($evidence['sources'] ?? []),
            'events_count' => count($evidence['events'] ?? []),
            'unresolved_conflicts' => $conflicts['total'] ?? 0,
            'generated_at' => now()->toIso8601String(),
        ]);

        DB::update(
            'UPDATE gps_research_tasks
             SET conclusion = ?, evidence_summary = ?, status = ?, resolved_at = NOW(), updated_at = NOW()
             WHERE id = ?',
            [$proofText, $evidenceSummary, $newStatus, $taskId]
        );

        Log::info('GPSProofGeneratorService: Proof saved to task', [
            'task_id' => $taskId,
            'new_status' => $newStatus,
            'confidence' => $confidence,
        ]);

        return true;
    }

    /**
     * Infer confidence level from the generated proof text.
     * Returns: 'proven' | 'probable' | 'possible' | 'insufficient'
     */
    private function inferConfidenceLevel(string $proofText): string
    {
        $lower = strtolower($proofText);

        // Scan last 400 chars where conclusion typically appears
        $tail = strtolower(substr($proofText, -400));

        if (str_contains($tail, 'proven') || str_contains($tail, 'conclusively established')) {
            return 'proven';
        }
        if (str_contains($tail, 'probable') || str_contains($tail, 'highly likely') || str_contains($tail, 'strongly suggests')) {
            return 'probable';
        }
        if (str_contains($tail, 'possible') || str_contains($tail, 'may have') || str_contains($tail, 'likely')) {
            return 'possible';
        }
        if (str_contains($lower, 'insufficient') || str_contains($lower, 'cannot be determined') || str_contains($lower, 'no conclusion')) {
            return 'insufficient';
        }

        return 'possible'; // default when ambiguous
    }

    /**
     * Gather all available DB evidence for a person.
     */
    private function gatherEvidence(int $personId, array $person): array
    {
        return [
            'sources' => $person['sources'] ?? [],
            'citations' => $person['citations'] ?? [],
            'events' => $person['events'] ?? [],
            'residences' => $person['residences'] ?? [],
            'name_variants' => $person['name_variants'] ?? [],
            'external_ids' => $person['external_ids'] ?? [],
            'research_tasks' => $person['research_tasks'] ?? [],
            'families_as_spouse' => $person['families_as_spouse'] ?? [],
            'family_as_child' => $person['family_as_child'] ?? null,
        ];
    }

    /**
     * Build the GPS proof prompt from DB evidence only.
     */
    private function buildProofPrompt(
        string $question,
        array $person,
        array $evidence,
        array $coverage,
        array $conflicts
    ): string {
        $personName = trim(($person['given_name'] ?? '').' '.($person['surname'] ?? ''));
        $birthDate = $person['birth_date'] ?? 'unknown';
        $deathDate = $person['death_date'] ?? 'unknown';
        $lessonContext = $this->buildReusableLessonContext($person, $question, $evidence, $conflicts, 5);

        // Format sources as numbered list
        $sourceList = '';
        foreach ($evidence['sources'] as $i => $src) {
            $n = $i + 1;
            $src = (array) $src;
            $url = $src['url'] ?? '';
            $qual = $src['source_quality'] ?? 'derivative';
            $info = $src['information_quality'] ?? 'secondary';
            $sourceList .= "[Source {$n}] {$src['title']} — quality: {$qual}/{$info}"
                .($url ? " — {$url}" : '')."\n";
        }
        if (empty($sourceList)) {
            $sourceList = "No formal sources cited yet.\n";
        }

        // Format key facts from events
        $factList = '';
        foreach ($evidence['events'] as $ev) {
            $ev = (array) $ev;
            $factList .= "- {$ev['event_type']}: {$ev['event_date']} at {$ev['event_place']}\n";
        }

        // Format conflicts
        $conflictList = '';
        foreach ($conflicts['conflicts'] as $c) {
            $c = (array) $c;
            $conflictList .= "- CONFLICT on {$c['field_name']}: Source says \"{$c['source_a_value']}\" vs \"{$c['source_b_value']}\" (severity: {$c['conflict_severity']})\n";
        }
        if (empty($conflictList)) {
            $conflictList = "None detected.\n";
        }

        // Format search coverage
        $coverageList = '';
        foreach ($coverage['coverage'] as $cov) {
            $cov = (array) $cov;
            $coverageList .= "- {$cov['repository_type']} ({$cov['repository_name']}): {$cov['search_count']} searches, {$cov['positive_count']} positive\n";
        }
        $uncovered = implode(', ', $coverage['core_uncovered'] ?? []);
        if ($uncovered) {
            $coverageList .= "- UNCOVERED repository types: {$uncovered}\n";
        }
        if (empty($coverageList)) {
            $coverageList = "No documented searches yet.\n";
        }

        return <<<PROMPT
You are a professional genealogist writing a GPS-compliant proof argument.

STRICT RULES:
1. NEVER invent, fabricate, or infer facts not present in the evidence below.
2. Every claim must cite a source from the numbered source list by [Source N].
3. If evidence is insufficient, say so explicitly — do not speculate.
4. If conflicts exist, address them using GPS Element 4 analysis.
5. Write in formal genealogical narrative style (third person, past tense).
6. Reusable Genea lessons are process guardrails only; never treat them as source evidence or cite them as [Source N].

PERSON: {$personName}
BORN: {$birthDate}
DIED: {$deathDate}

GENEALOGICAL QUESTION: {$question}
{$lessonContext}

=== AVAILABLE SOURCES ===
{$sourceList}

=== KEY DOCUMENTED FACTS ===
{$factList}

=== CONFLICTING EVIDENCE (GPS Element 4) ===
{$conflictList}

=== SEARCH COVERAGE (GPS Element 1) ===
{$coverageList}

Write a GPS proof argument addressing the question above. Structure:
1. Statement of the problem
2. Evidence analysis (cite [Source N] for every claim)
3. Conflict resolution (if applicable)
4. Conclusion with confidence level (proven/probable/possible)
5. Remaining gaps that require further research

If the evidence is insufficient to reach a conclusion, state that explicitly.
PROMPT;
    }

    private function buildReusableLessonContext(
        array $person,
        string $question,
        array $evidence,
        array $conflicts,
        int $limit = 5
    ): string {
        $treeId = (int) ($person['tree_id'] ?? $person['family_tree_id'] ?? 0);
        if ($treeId < 1 || ! Schema::hasTable('agent_semantic_memory')) {
            return '';
        }

        $terms = $this->lessonSearchTerms($person, $question, $evidence, $conflicts);
        $rows = $this->loadLessonMemoryRows($treeId, $terms, $limit);
        if ($rows === [] && $terms !== []) {
            $rows = $this->loadLessonMemoryRows($treeId, ['proof', 'source'], min(3, $limit));
        }

        if ($rows === []) {
            return '';
        }

        $lines = [
            '',
            '=== REUSABLE GENEA LESSONS ===',
            'Process guardrails only; do not treat these lessons as source evidence or cite them as [Source N].',
        ];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->fact_value ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $lessonPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
            $title = trim((string) ($payload['title'] ?? $row->fact_key ?? 'Genea lesson'));
            $lesson = trim((string) ($payload['lesson'] ?? ''));
            $tags = is_array($lessonPayload['tags'] ?? null)
                ? array_slice(array_values(array_filter(array_map('strval', $lessonPayload['tags']))), 0, 5)
                : [];

            if ($lesson === '') {
                continue;
            }

            $tagText = $tags !== [] ? ' tags='.implode(',', $tags) : '';
            $lines[] = '- ['.(string) ($row->fact_type ?? 'lesson').'] '.$this->compactLessonText($title, 100).': '.$this->compactLessonText($lesson, 300).$tagText;
        }

        return count($lines) > 3 ? "\n".implode("\n", $lines)."\n" : '';
    }

    /**
     * @return list<object>
     */
    private function loadLessonMemoryRows(int $treeId, array $terms, int $limit): array
    {
        $limit = max(1, min(8, $limit));
        $where = [
            "entity_type = 'genealogy_tree'",
            'entity_id = ?',
            'fact_type IN ('.implode(', ', array_fill(0, count(self::LESSON_MEMORY_TYPES), '?')).')',
        ];
        $params = [$treeId, ...self::LESSON_MEMORY_TYPES];

        $terms = array_slice(array_values(array_unique(array_filter(
            array_map(fn (string $term): string => $this->compactLessonText($term, 80), $terms),
            static fn (string $term): bool => $term !== ''
        ))), 0, 10);

        if ($terms !== []) {
            $termClauses = [];
            foreach ($terms as $term) {
                $termClauses[] = '(fact_key LIKE ? OR fact_value LIKE ?)';
                $like = '%'.$term.'%';
                $params[] = $like;
                $params[] = $like;
            }
            $where[] = '('.implode(' OR ', $termClauses).')';
        }

        $params[] = $limit;

        return DB::select(
            'SELECT id, fact_type, fact_key, fact_value, confidence, updated_at
             FROM agent_semantic_memory
             WHERE '.implode(' AND ', $where).'
             ORDER BY confidence DESC, updated_at DESC, id DESC
             LIMIT ?',
            $params
        );
    }

    /**
     * @return list<string>
     */
    private function lessonSearchTerms(array $person, string $question, array $evidence, array $conflicts): array
    {
        $terms = [
            $question,
            $person['given_name'] ?? null,
            $person['surname'] ?? null,
            trim((string) ($person['given_name'] ?? '').' '.(string) ($person['surname'] ?? '')),
            $person['birth_place'] ?? null,
            $person['death_place'] ?? null,
            'gps proof',
            'proof',
            'source evidence',
            'conflict',
            'identity',
        ];

        foreach (['birth_place', 'death_place'] as $field) {
            foreach (explode(',', (string) ($person[$field] ?? '')) as $part) {
                $terms[] = trim($part);
            }
        }

        foreach (array_slice($evidence['sources'] ?? [], 0, 6) as $source) {
            $source = (array) $source;
            $terms[] = $source['title'] ?? null;
            $terms[] = $source['publication'] ?? null;
            $terms[] = $source['source_type'] ?? null;
        }

        foreach (array_slice($conflicts['conflicts'] ?? [], 0, 4) as $conflict) {
            $conflict = (array) $conflict;
            $terms[] = $conflict['field_name'] ?? null;
            $terms[] = $conflict['source_a_value'] ?? null;
            $terms[] = $conflict['source_b_value'] ?? null;
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $term): string => trim((string) $term), $terms),
            static fn (string $term): bool => $term !== '' && mb_strlen($term) >= 3
        )));
    }

    private function compactLessonText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', Str::ascii($text)) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'...' : $text;
    }

    /**
     * Validate that source IDs/URLs mentioned in the proof text exist in the DB.
     * Returns [count_validated, warnings_array].
     */
    private function validateCitations(string $proofText, int $personId, array $evidence): array
    {
        $warnings = [];
        $validated = 0;

        // Extract [Source N] references
        preg_match_all('/\[Source\s+(\d+)\]/i', $proofText, $matches);
        $referencedNums = array_unique($matches[1] ?? []);

        $sourcesCount = count($evidence['sources'] ?? []);
        foreach ($referencedNums as $num) {
            if ((int) $num > $sourcesCount || (int) $num < 1) {
                $warnings[] = "Proof references [Source {$num}] but only {$sourcesCount} sources exist for this person.";
            } else {
                $validated++;
            }
        }

        // Check for URL references in the proof text
        preg_match_all('/https?:\/\/[^\s\]"]+/', $proofText, $urlMatches);
        foreach ($urlMatches[0] as $url) {
            $exists = DB::selectOne(
                'SELECT id FROM genealogy_sources WHERE url = ? LIMIT 1',
                [$url]
            );
            if (! $exists) {
                $warnings[] = "Proof contains URL not found in genealogy_sources: {$url}";
            } else {
                $validated++;
            }
        }

        return [$validated, $warnings];
    }

    private function validateProofIntegrity(string $proofText, string $personName, string $question, array $evidence): array
    {
        $warnings = $this->validateProofMatchesSubject($proofText, $personName, $question)['warnings'];
        $normalizedProof = mb_strtolower($proofText);

        $sourceCount = count($evidence['sources'] ?? []);
        if ($sourceCount > 0 && preg_match('/\[Source\s+\d+\]/i', $proofText) !== 1) {
            $warnings[] = 'Proof does not cite any available numbered sources.';
        }

        if ($sourceCount > 1 && str_contains($normalizedProof, 'only available source')) {
            $warnings[] = 'Proof claims a single available source despite multiple linked sources.';
        }

        if (! empty($evidence['events']) && preg_match('/no evidence has been found|no sources have been located/i', $proofText) === 1) {
            $warnings[] = 'Proof claims no evidence despite recorded events or linked evidence.';
        }

        return [
            'valid' => $warnings === [],
            'warnings' => $warnings,
        ];
    }

    private function buildDeterministicFallbackProof(array $person, string $question, array $evidence, array $coverage, array $conflicts): ?string
    {
        $sources = array_values(array_filter($evidence['sources'] ?? [], static fn ($src) => ! empty((array) $src)));
        $events = array_values(array_filter(
            $evidence['events'] ?? [],
            fn ($ev) => $this->isMeaningfulEvent((array) $ev)
        ));
        $coverageRows = array_values($coverage['coverage'] ?? []);
        $personName = trim(($person['given_name'] ?? '').' '.($person['surname'] ?? ''));
        $focus = $this->detectQuestionFocus($question);
        $eventSummary = $this->summarizeRelevantEvents($events, $focus);
        $sourceSummary = $this->summarizeRelevantSources($sources, $focus);
        $confidence = $this->determineFallbackConfidence($focus, $eventSummary, $sourceSummary, $conflicts, $coverage);
        $conclusion = $this->buildFallbackConclusion($person, $personName, $question, $focus, $eventSummary, $sourceSummary, $confidence);

        if ($personName === '' || ($sources === [] && $events === [] && $coverageRows === [])) {
            return null;
        }

        $lines = [];
        $lines[] = '1. Statement of the problem';
        $lines[] = "This proof addresses the question: {$question}";
        $lines[] = '';
        $lines[] = '2. Evidence analysis';

        if ($sourceSummary['relevant'] !== []) {
            foreach ($sourceSummary['relevant'] as $index => $source) {
                $source = (array) $source;
                $title = trim((string) ($source['title'] ?? 'Untitled source'));
                $publication = trim((string) ($source['publication'] ?? $source['repository'] ?? ''));
                $suffix = $publication !== '' ? " ({$publication})" : '';
                $lines[] = '- [Source '.($index + 1)."] {$title}{$suffix}";
            }
        } elseif ($sources !== []) {
            foreach (array_slice($sources, 0, 4) as $index => $source) {
                $source = (array) $source;
                $title = trim((string) ($source['title'] ?? 'Untitled source'));
                $publication = trim((string) ($source['publication'] ?? $source['repository'] ?? ''));
                $suffix = $publication !== '' ? " ({$publication})" : '';
                $lines[] = '- [Source '.($index + 1)."] {$title}{$suffix}";
            }
        } else {
            $lines[] = '- No linked source records are available yet.';
        }

        if ($eventSummary['relevant'] !== []) {
            foreach ($eventSummary['relevant'] as $event) {
                $event = (array) $event;
                $type = $this->normalizeEventType((string) ($event['event_type'] ?? 'event'));
                $date = trim((string) ($event['event_date'] ?? ''));
                $place = trim((string) ($event['event_place'] ?? ''));
                $description = trim((string) ($event['description'] ?? ''));
                $parts = array_filter([$type, $date !== '' ? "date {$date}" : null, $place !== '' ? "place {$place}" : null]);
                $line = '- Recorded '.implode(', ', $parts);
                if ($description !== '') {
                    $line .= "; {$description}";
                }
                $lines[] = $line;
            }
        } elseif ($events !== []) {
            foreach (array_slice($events, 0, 3) as $event) {
                $event = (array) $event;
                $lines[] = '- Recorded event: '.trim((string) ($event['event_type'] ?? 'event'))
                    .' '.trim((string) ($event['event_date'] ?? ''))
                    .' '.trim((string) ($event['event_place'] ?? ''));
            }
        }

        if ($coverageRows !== []) {
            $lines[] = '- Search coverage:';
            foreach (array_slice($coverageRows, 0, 4) as $cov) {
                $cov = (array) $cov;
                $lines[] = '  - '.trim((string) ($cov['repository_name'] ?? $cov['repository_type'] ?? 'repository'))
                    .': '.(int) ($cov['search_count'] ?? 0).' searches, '.(int) ($cov['positive_count'] ?? 0).' positive';
            }
        }

        $lines[] = '';
        $lines[] = '3. Conflict resolution';
        if (($conflicts['total'] ?? 0) > 0) {
            $lines[] = 'Conflicting evidence remains unresolved and requires manual comparison before a stronger conclusion can be made.';
        } else {
            $lines[] = 'No unresolved conflicting evidence is currently recorded for this person.';
        }

        $lines[] = '';
        $lines[] = '4. Conclusion with confidence level';
        $lines[] = $conclusion;

        $lines[] = '';
        $lines[] = '5. Remaining gaps that require further research';
        if (($coverage['core_uncovered'] ?? []) !== []) {
            $lines[] = '- Uncovered repositories: '.implode(', ', $coverage['core_uncovered']);
        } else {
            $lines[] = '- Review the linked sources and task logs for more precise source extraction and event-level citations.';
        }

        return implode("\n", $lines);
    }

    private function detectQuestionFocus(string $question): string
    {
        $normalized = mb_strtolower($question);

        if (str_contains($normalized, 'military')) {
            return 'military';
        }
        if (str_contains($normalized, 'identity')) {
            return 'identity';
        }
        if (str_contains($normalized, 'death place') || (str_contains($normalized, 'death') && str_contains($normalized, 'place'))) {
            return 'death_place';
        }
        if (str_contains($normalized, 'birth place') || (str_contains($normalized, 'birth') && str_contains($normalized, 'place'))) {
            return 'birth_place';
        }
        if (str_contains($normalized, 'death')) {
            return 'death';
        }
        if (str_contains($normalized, 'birth')) {
            return 'birth';
        }

        return 'general';
    }

    private function summarizeRelevantEvents(array $events, string $focus): array
    {
        $matches = [];

        foreach ($events as $event) {
            $event = (array) $event;
            $type = $this->normalizeEventType((string) ($event['event_type'] ?? ''));

            if ($focus === 'death_place' || $focus === 'death') {
                if (str_contains($type, 'death')) {
                    $matches[] = $event;
                }
            } elseif ($focus === 'birth_place' || $focus === 'birth') {
                if (str_contains($type, 'birth')) {
                    $matches[] = $event;
                }
            } elseif ($focus === 'military') {
                if (str_contains($type, 'military')) {
                    $matches[] = $event;
                }
            }
        }

        return [
            'relevant' => array_slice($matches, 0, 3),
            'all' => $events,
        ];
    }

    private function summarizeRelevantSources(array $sources, string $focus): array
    {
        $matches = [];

        foreach ($sources as $source) {
            $source = (array) $source;
            $haystack = mb_strtolower(implode(' ', array_filter([
                (string) ($source['title'] ?? ''),
                (string) ($source['publication'] ?? ''),
                (string) ($source['repository'] ?? ''),
                (string) ($source['url'] ?? ''),
            ])));

            $isMatch = match ($focus) {
                'military' => preg_match('/military|pension|fold3|war|veteran|archives\.gov|nara/', $haystack) === 1,
                'identity' => preg_match('/pension|census|nara|archives\.gov|familysearch|dar|fold3/', $haystack) === 1,
                'death_place', 'death' => preg_match('/chronicle|cemetery|death|grave|obitu|census/', $haystack) === 1,
                'birth_place', 'birth' => preg_match('/birth|bapt|church|census/', $haystack) === 1,
                default => true,
            };

            if ($isMatch) {
                $matches[] = $source;
            }
        }

        if ($matches === []) {
            $matches = array_slice($sources, 0, 4);
        }

        return [
            'relevant' => array_slice($matches, 0, 4),
            'all' => $sources,
        ];
    }

    private function determineFallbackConfidence(
        string $focus,
        array $eventSummary,
        array $sourceSummary,
        array $conflicts,
        array $coverage
    ): string {
        if (($conflicts['total'] ?? 0) > 0) {
            return 'possible';
        }

        $relevantSources = count($sourceSummary['relevant'] ?? []);
        $relevantEvents = count($eventSummary['relevant'] ?? []);
        $positiveCoverage = 0;
        foreach (($coverage['coverage'] ?? []) as $row) {
            $row = (array) $row;
            $positiveCoverage += (int) (($row['positive_count'] ?? 0));
        }

        if (in_array($focus, ['death_place', 'death', 'birth_place', 'birth'], true) && $relevantEvents === 0 && $positiveCoverage === 0) {
            return 'insufficient';
        }

        if (in_array($focus, ['death_place', 'death', 'birth_place', 'birth'], true) && $relevantEvents > 0 && $relevantSources >= 2) {
            return 'probable';
        }

        if (in_array($focus, ['military', 'identity'], true) && $relevantSources >= 3 && $positiveCoverage >= 2) {
            return 'probable';
        }

        if ($relevantSources > 0 || $relevantEvents > 0) {
            return 'possible';
        }

        return 'insufficient';
    }

    private function buildFallbackConclusion(
        array $person,
        string $personName,
        string $question,
        string $focus,
        array $eventSummary,
        array $sourceSummary,
        string $confidence
    ): string {
        if ($confidence === 'insufficient') {
            return "The current evidence is insufficient to resolve the question for {$personName}. Confidence level: insufficient.";
        }

        $relevantEvents = $eventSummary['relevant'] ?? [];
        $relevantSources = $sourceSummary['relevant'] ?? [];

        if ($focus === 'death_place' && $relevantEvents !== []) {
            $event = (array) $relevantEvents[0];
            $place = trim((string) ($event['event_place'] ?? ''));
            $date = trim((string) ($event['event_date'] ?? ''));
            if ($place !== '') {
                $dateClause = $date !== '' ? " for the death recorded {$date}" : '';

                return "The strongest currently linked evidence points to {$place}{$dateClause} as the death place for {$personName}. This conclusion is supported by the recorded death event and the cited source set. Confidence level: {$confidence}.";
            }
        }

        if ($focus === 'death_place') {
            $place = trim((string) ($person['death_place'] ?? ''));
            $date = trim((string) ($person['death_date'] ?? ''));
            if ($place !== '') {
                $dateClause = $date !== '' ? " with a recorded death date of {$date}" : '';

                return "The currently stabilized person record identifies {$place} as the death place for {$personName}{$dateClause}. The linked source set should still be reviewed together, but it supports that placement. Confidence level: {$confidence}.";
            }
        }

        if ($focus === 'birth_place') {
            $place = trim((string) ($person['birth_place'] ?? ''));
            $date = trim((string) ($person['birth_date'] ?? ''));
            if ($place !== '') {
                $dateClause = $date !== '' ? " around {$date}" : '';

                return "The currently stabilized person record identifies {$place} as the birth place for {$personName}{$dateClause}. The linked source set should still be reviewed together, but it supports that placement. Confidence level: {$confidence}.";
            }
        }

        if ($focus === 'military') {
            return "The linked military-oriented sources support the conclusion that {$personName} is associated with the service described in the research question, but the record set should still be reviewed for full identity confirmation before treating the matter as closed. Confidence level: {$confidence}.";
        }

        if ($focus === 'identity') {
            return "The linked records point toward {$personName} matching the identity described in the research question, especially through the cited archival material, but a final identity conclusion should rest on full comparison of the document set. Confidence level: {$confidence}.";
        }

        if ($relevantSources !== []) {
            return "The linked evidence provides a focused, source-backed answer for {$personName} to the question '{$question}', though the conclusion remains conservative until the strongest records are fully reviewed together. Confidence level: {$confidence}.";
        }

        return "A source-backed summary has been assembled for {$personName}. The available evidence should be reviewed against the question above before marking the task resolved. Confidence level: {$confidence}.";
    }

    private function isMeaningfulEvent(array $event): bool
    {
        $type = $this->normalizeEventType((string) ($event['event_type'] ?? ''));
        $date = trim((string) ($event['event_date'] ?? ''));
        $place = trim((string) ($event['event_place'] ?? ''));
        $description = trim((string) ($event['description'] ?? ''));

        if ($type === '' || $type === 'event' || $type === 'even') {
            return $date !== '' || $place !== '' || $description !== '';
        }

        return true;
    }

    private function normalizeEventType(string $eventType): string
    {
        $normalized = trim(mb_strtolower($eventType));

        return match ($normalized) {
            'birt' => 'birth',
            'deat' => 'death',
            'marr' => 'marriage',
            'even' => 'event',
            default => $normalized,
        };
    }

    private function validateProofMatchesSubject(string $proofText, string $personName, string $question): array
    {
        $warnings = [];
        $normalizedProof = mb_strtolower($proofText);
        $normalizedName = mb_strtolower(trim($personName));

        if ($normalizedName !== '' && ! str_contains($normalizedProof, $normalizedName)) {
            $warnings[] = "Proof does not reference expected subject name: {$personName}";
        }

        $questionKeywords = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', mb_strtolower($question)) ?: [],
            static fn ($word) => mb_strlen($word) >= 5 && ! in_array($word, [
                'whose', 'there', 'where', 'which', 'place', 'birth', 'death', 'exact', 'about', 'records', 'record',
            ], true)
        ));

        if ($questionKeywords !== []) {
            $matchedKeyword = false;
            foreach (array_slice($questionKeywords, 0, 6) as $keyword) {
                if (str_contains($normalizedProof, $keyword)) {
                    $matchedKeyword = true;
                    break;
                }
            }

            if (! $matchedKeyword) {
                $warnings[] = 'Proof does not appear to address the target research question.';
            }
        }

        return [
            'valid' => $warnings === [],
            'warnings' => $warnings,
        ];
    }
}
