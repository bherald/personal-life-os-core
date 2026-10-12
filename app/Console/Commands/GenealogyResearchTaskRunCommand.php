<?php

namespace App\Console\Commands;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AgentLoopService;
use App\Services\Genealogy\GenealogyCleanRoomGuidanceService;
use App\Services\Genealogy\GenealogyService;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GenealogyResearchTaskRunCommand extends Command
{
    protected $signature = 'genealogy:research-task-run
                            {--tree=4 : Tree ID to target}
                            {--task-id=* : Existing genealogy_research_tasks ID to process}
                            {--limit=1 : Max queued tasks to process when --task-id is omitted}
                            {--max-iterations=8 : Agent max iterations per task}
                            {--timeout-minutes=20 : Agent timeout budget per task}
                            {--use-current-cli-settings : Override saved task settings with the current CLI session}
                            {--save-cli-settings : Save that explicit override to the task when executing}
                            {--execute : Execute selected task(s); default is dry-run}
                            {--confirm-non-face : Confirm this run is not derived from face/cluster linkage}
                            {--confirm-review-write : Confirm review-first proposal/log writes are allowed}
                            {--confirm-person-creation : Confirm non-face person creation may proceed through approved review/apply paths}
                            {--confirm-canonical-facts : Confirm source-backed canonical fact writes may proceed through approved review/apply paths}
                            {--confirm-downloads : Confirm evidence downloads may proceed through tool-level download/storage confirmations}
                            {--confirm-writeback : Confirm non-face genealogy writeback may proceed through approved bounded tools}
                            {--confirm-scheduled-enablement : Confirm scheduled non-face genealogy lanes are operator-approved for this work window}
                            {--confirm-unanchored : Confirm unanchored broad research tasks may run}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Run bounded non-face Genea research tasks from genealogy_research_tasks';

    public function handle(AgentLoopService $agentLoop, GenealogyCleanRoomGuidanceService $cleanRoomGuidance): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $limit = max(1, min(10, (int) $this->option('limit')));
        $execute = (bool) $this->option('execute');
        $confirmedNonFace = (bool) $this->option('confirm-non-face');
        $confirmedReviewWrite = (bool) $this->option('confirm-review-write');
        $permissions = $this->permissionFlags();
        $maxIterations = max(1, min(12, (int) $this->option('max-iterations')));
        $timeoutMinutes = max(1, min(60, (int) $this->option('timeout-minutes')));

        $tasks = $this->selectedTasks($treeId, $limit);

        $payload = [
            'command' => 'genealogy:research-task-run',
            'mode' => $execute ? 'execute' : 'dry_run',
            'tree_id' => $treeId,
            'selected_count' => count($tasks),
            'confirmations' => [
                'non_face' => $confirmedNonFace,
                'review_write' => $confirmedReviewWrite,
                'person_creation' => $permissions['person_creation'],
                'canonical_facts' => $permissions['canonical_facts'],
                'downloads' => $permissions['downloads'],
                'writeback' => $permissions['writeback'],
                'scheduled_enablement' => $permissions['scheduled_enablement'],
                'unanchored' => $permissions['unanchored'],
            ],
            'posture' => [
                'face_linking_enabled' => false,
                'face_cluster_actions_enabled' => false,
                'person_creation_enabled' => $execute && $permissions['person_creation'],
                'canonical_apply_enabled' => $execute && $permissions['canonical_facts'],
                'evidence_downloads_enabled' => $execute && $permissions['downloads'],
                'writeback_enabled' => $execute && $permissions['writeback'],
                'scheduled_enablement_acknowledged' => $execute && $permissions['scheduled_enablement'],
                'review_first_writes_enabled' => $execute && $confirmedReviewWrite,
                'task_status_writes_enabled' => $execute,
            ],
            'research_guidance' => $cleanRoomGuidance->summary(),
            'tasks' => array_map(fn (object $task): array => $this->taskPlan($task, $timeoutMinutes, $permissions), $tasks),
            'results' => [],
        ];

        if (! $execute) {
            return $this->emit($payload);
        }

        if (! $confirmedNonFace || ! $confirmedReviewWrite) {
            $payload['status'] = 'blocked';
            $payload['error'] = 'Execution requires --confirm-non-face and --confirm-review-write.';

            return $this->emit($payload, 2);
        }

        foreach ($tasks as $task) {
            $payload['results'][] = $this->runTask($agentLoop, $cleanRoomGuidance, $task, $maxIterations, $timeoutMinutes, $permissions);
        }

        $payload['status'] = collect($payload['results'])->contains(fn (array $row): bool => ($row['status'] ?? null) === 'failed')
            ? 'failed'
            : 'ok';

        return $this->emit($payload, $payload['status'] === 'ok' ? 0 : 1);
    }

    /**
     * @return array<int,object>
     */
    private function selectedTasks(int $treeId, int $limit): array
    {
        $taskIds = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            (array) $this->option('task-id')
        )));
        $taskIds = array_values(array_filter($taskIds, static fn (int $id): bool => $id > 0));

        if ($taskIds !== []) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));

            return DB::select(
                "SELECT *
                 FROM genealogy_research_tasks
                 WHERE tree_id = ?
                   AND id IN ({$placeholders})
                 ORDER BY id",
                array_merge([$treeId], $taskIds)
            );
        }

        return DB::select(
            "SELECT *
             FROM genealogy_research_tasks
             WHERE tree_id = ?
               AND status = 'queued'
             ORDER BY
                CASE priority
                    WHEN 'urgent' THEN 0
                    WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2
                    ELSE 3
                END,
                created_at ASC,
                id ASC
             LIMIT ?",
            [$treeId, $limit]
        );
    }

    /**
     * @param  array{person_creation:bool,canonical_facts:bool,downloads:bool,writeback:bool,scheduled_enablement:bool,unanchored:bool}|null  $permissions
     */
    private function taskPlan(object $task, ?int $timeoutMinutes = null, ?array $permissions = null): array
    {
        $blocked = $this->blockedTaskReason($task, $permissions, $timeoutMinutes);
        $execution = null;
        try {
            $execution = $this->taskExecutionContext($task);
        } catch (RuntimeException $error) {
            $blocked ??= $error->getMessage();
        }

        return [
            'id' => (int) $task->id,
            'person_id' => $task->person_id === null ? null : (int) $task->person_id,
            'task_type' => (string) $task->task_type,
            'status' => (string) $task->status,
            'priority' => (string) $task->priority,
            'outcome_state' => $task->outcome_state,
            'blocked_reason' => $blocked,
            'question_preview' => $execution === null ? mb_strimwidth((string) $task->research_question, 0, 180, '...') : (string) $task->research_question,
            'cli_execution_settings' => $execution['settings'] ?? null,
            'execution_settings_source' => $execution === null ? 'plos_defaults'
                : ($this->option('use-current-cli-settings') || empty($task->cli_execution_scope) ? 'current' : 'saved'),
            'save_cli_settings' => (bool) $this->option('save-cli-settings'),
        ];
    }

    /**
     * @param  array{person_creation:bool,canonical_facts:bool,downloads:bool,writeback:bool,scheduled_enablement:bool,unanchored:bool}  $permissions
     */
    private function runTask(
        AgentLoopService $agentLoop,
        GenealogyCleanRoomGuidanceService $cleanRoomGuidance,
        object $task,
        int $maxIterations,
        int $timeoutMinutes,
        array $permissions
    ): array {
        $taskId = (int) $task->id;
        $treeId = (int) $task->tree_id;
        $blocked = $this->blockedTaskReason($task, $permissions, $timeoutMinutes);
        if ($blocked !== null) {
            return [
                'task_id' => $taskId,
                'status' => 'skipped',
                'reason' => $blocked,
            ];
        }

        try {
            $execution = $this->taskExecutionContext($task);
            $previousResults = $this->decodeTaskJson($task->results ?? null);
            if ($execution !== null && ($task->results ?? null) !== null && ! is_array(json_decode($task->results, true))) {
                throw new RuntimeException('Saved task results are invalid; they were not overwritten.');
            }
            $history = $previousResults['cli_runs'] ?? [];
            if ($execution !== null && (! is_array($history) || ! array_is_list($history))) {
                throw new RuntimeException('Saved task result history is invalid; it was not overwritten.');
            }
        } catch (RuntimeException $error) {
            return ['task_id' => $taskId, 'status' => 'failed', 'reason' => $error->getMessage()];
        }
        $saveScope = $this->option('save-cli-settings') ? ', cli_execution_scope = ?' : '';

        $claimed = DB::update(
            "UPDATE genealogy_research_tasks
             SET status = 'processing',
                 started_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP{$saveScope}
             WHERE id = ?
               AND tree_id = ?
               AND status = 'queued'",
            array_merge($saveScope === '' ? [] : [json_encode($execution, JSON_THROW_ON_ERROR)], [$taskId, $treeId])
        );

        if ($claimed === 0) {
            return [
                'task_id' => $taskId,
                'status' => 'skipped',
                'reason' => 'Task was not queued at claim time.',
            ];
        }

        $personName = $task->person_id === null
            ? null
            : $this->personName($treeId, (int) $task->person_id);
        $hasAnchorPerson = $task->person_id !== null;
        $taskParameters = $this->decodeTaskJson($task->parameters ?? null);
        $relatedPeople = $this->decodeTaskJson($task->related_people_used ?? null);
        $sourcesChecked = $this->decodeTaskJson($task->sources_checked ?? null);
        $runStartedAt = date('Y-m-d H:i:s');

        $run = fn () => $agentLoop->execute('genealogy-records', $this->taskPrompt($task, $cleanRoomGuidance, $permissions), [
            'tree_id' => $treeId,
            'session_id' => 'genealogy-direct-task-'.$taskId.'-'.uniqid(),
            'notify' => false,
            'index_findings' => false,
            'capture_procedural_memory' => false,
            'capture_episodic_memory' => false,
            'record_adaptive_outcome' => false,
            'max_iterations' => $maxIterations,
            'timeout_minutes' => $timeoutMinutes,
            'context' => [
                'direct_genealogy_task_mode' => true,
                'queue_mode' => $hasAnchorPerson,
                'queue_quality_analysis' => true,
                'skip_assess' => $hasAnchorPerson,
                'genealogy_task_id' => $taskId,
                'target_person_id' => $task->person_id === null ? null : (int) $task->person_id,
                'target_person_name' => $personName,
                'research_question' => (string) $task->research_question,
                'selection_reason' => (string) ($task->selection_reason ?? ''),
                'scope_reason' => (string) ($task->scope_reason ?? ''),
                'evidence_summary' => (string) ($task->evidence_summary ?? ''),
                'conflicts_found' => (string) ($task->conflicts_found ?? ''),
                'related_people_used' => $relatedPeople,
                'sources_checked' => $sourcesChecked,
                'task_parameters' => $taskParameters,
                'question_type' => (string) ($task->task_type ?? ''),
                'non_face_confirmed' => true,
                'operator_permissions' => $permissions,
            ],
        ]);

        $compute = [];
        $codex = [];
        $effectiveExecution = null;
        $invoke = function () use ($run, &$effectiveExecution): array {
            $effectiveExecution = CliWorkflowExecutionScope::current();

            return $run();
        };
        try {
            $result = $execution === null ? $run() : (new CliWorkflowExecutionScope)->runCaptured($execution, $invoke,
                function (array $output) use (&$compute): void {
                    $compute[] = $output;
                },
                function (array $output) use (&$codex): void {
                    $codex[] = $output;
                });
        } catch (\Throwable $error) {
            $result = ['success' => false, 'response' => '', 'error' => $error->getMessage(), 'tool_calls' => []];
        }
        if ($execution !== null) {
            $history[] = ['settings' => ($effectiveExecution ?? $execution)['settings'], 'started_at' => $runStartedAt,
                'input' => ['research_question' => $task->research_question, 'selection_reason' => $task->selection_reason ?? null,
                    'scope_reason' => $task->scope_reason ?? null, 'evidence_summary' => $task->evidence_summary ?? null,
                    'conflicts_found' => $task->conflicts_found ?? null, 'related_people_used' => $relatedPeople,
                    'sources_checked' => $sourcesChecked, 'parameters' => $taskParameters],
                'agent_result' => $result, 'compute_executions' => $compute, 'codex_executions' => $codex];
        }

        $findingsCount = 0;
        $reviewItemsCount = 0;
        foreach ($result['tool_calls'] ?? [] as $toolCall) {
            if (! ($toolCall['success'] ?? false)) {
                continue;
            }
            if (($toolCall['outcome_eligible'] ?? true) === false) {
                continue;
            }

            $tool = $toolCall['tool'] ?? '';
            if (in_array($tool, ['propose_change', 'propose_relationship'], true)) {
                $findingsCount++;
            }

            if ($tool === 'submit_for_review') {
                $reviewItemsCount++;
            }
        }

        $rawFindingsCount = $findingsCount;
        $rawReviewItemsCount = $reviewItemsCount;
        $reviewOutcome = $hasAnchorPerson
            ? $this->reviewOutcomeCounts((int) $task->person_id, $runStartedAt)
            : null;
        if ($reviewOutcome !== null && $rawReviewItemsCount > 0) {
            $reviewItemsCount = $reviewOutcome['reviewable_count'];
            if ($reviewItemsCount === 0) {
                $findingsCount = 0;
            }
        }

        $parsed = $this->parseOutcome((string) ($result['response'] ?? ''), $cleanRoomGuidance);
        $success = (bool) ($result['success'] ?? false);
        $outcomeState = $parsed['outcome_state']
            ?? ($success ? ($findingsCount > 0 ? 'completed' : 'deferred') : 'requeue');
        $outcomeReason = $parsed['outcome_reason']
            ?? ($success
                ? ($findingsCount > 0 ? 'Evidence-backed findings generated.' : 'No supported change found; preserve for future follow-up.')
                : ($result['error'] ?? 'Agent execution failed.'));
        $processedZeroSelectedPeople = $success
            && $findingsCount === 0
            && preg_match('/Processed\s+0\s+of\s+[1-9]\d*\s+persons/i', (string) ($result['response'] ?? '')) === 1;
        if ($processedZeroSelectedPeople) {
            $outcomeState = 'requeue';
            $outcomeReason = 'Agent selected target person(s) but produced no validated person report; keep queued for scoped follow-up.';
        }

        if ($success
            && $reviewOutcome !== null
            && $rawReviewItemsCount > 0
            && $reviewItemsCount === 0
            && in_array($outcomeState, ['completed', 'needs_human_review'], true)) {
            $outcomeState = 'deferred';
            $outcomeReason = $reviewOutcome['rejected_count'] > 0
                ? 'Generated proposal was auto-rejected by quality gates; no operator-reviewable item remains.'
                : 'Generated proposal did not materialize an operator-reviewable row.';
        }

        $status = $success && $outcomeState !== 'requeue' ? 'completed' : ($success ? 'queued' : 'failed');
        $controllerFailure = InferenceAdmissionException::sanitizeControllerFailure(
            is_array($result['controller_failure'] ?? null) ? $result['controller_failure'] : []
        );

        DB::update(
            "UPDATE genealogy_research_tasks
             SET status = ?,
                 results = ?,
                 scope_reason = ?,
                 related_people_used = ?,
                 sources_checked = ?,
                 evidence_summary = ?,
                 conflicts_found = ?,
                 outcome_state = ?,
                 outcome_reason = ?,
                 started_at = CASE WHEN ? = 'queued' THEN NULL ELSE started_at END,
                 completed_at = CASE WHEN ? IN ('completed', 'failed') THEN CURRENT_TIMESTAMP ELSE NULL END,
                 error_message = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tree_id = ?",
            [
                $status,
                json_encode([
                    'tool_calls_count' => count($result['tool_calls'] ?? []),
                    'findings_count' => $findingsCount,
                    'review_items_count' => $reviewItemsCount,
                    'raw_findings_count' => $rawFindingsCount,
                    'raw_review_items_count' => $rawReviewItemsCount,
                    'review_row_counts' => $reviewOutcome,
                    'duration_ms' => $result['duration_ms'] ?? null,
                    'tokens_used' => $result['tokens_used'] ?? null,
                    'direct_task_runner' => true,
                    'research_guidance_version' => $parsed['research_guidance']['version'],
                    'evidence_packets' => $parsed['research_guidance']['evidence_packets'],
                    'hypothesis_ledger' => $parsed['research_guidance']['hypothesis_ledger'],
                    'agent_activity_trace' => $parsed['research_guidance']['agent_activity_trace'],
                    'source_tool_gaps' => $parsed['research_guidance']['source_tool_gaps'],
                    'review_gates' => $parsed['research_guidance']['review_gates'],
                    'research_marker_warnings' => $parsed['research_guidance']['marker_warnings'],
                ] + ($execution === null ? [] : ['cli_runs' => $history])
                    + ($controllerFailure === [] ? [] : ['controller_failure' => $controllerFailure]), JSON_THROW_ON_ERROR),
                $parsed['scope_reason'] ?? 'none',
                json_encode($parsed['related_people_used'] ?? []),
                json_encode($parsed['sources_checked'] ?? []),
                $parsed['evidence_summary'] ?? ($execution === null ? mb_substr(trim((string) ($result['response'] ?? '')), 0, 500) : (string) ($result['response'] ?? '')),
                $parsed['conflicts_found'] ?? 'none',
                $outcomeState,
                $outcomeReason,
                $status,
                $status,
                $success ? null : ($result['error'] ?? 'unknown'),
                $taskId,
                $treeId,
            ]
        );

        if ($hasAnchorPerson) {
            app(GenealogyService::class)->markPersonAgentWorked($treeId, (int) $task->person_id);
        }

        return [
            'task_id' => $taskId,
            'status' => $status,
            'success' => $success,
            'outcome_state' => $outcomeState,
            'findings_count' => $findingsCount,
            'review_items_count' => $reviewItemsCount,
        ] + ($controllerFailure === [] ? [] : ['controller_failure' => $controllerFailure])
            + ($execution === null ? [] : ['agent_result' => $result, 'compute_executions' => $compute, 'codex_executions' => $codex]);
    }

    private function taskExecutionContext(object $task): ?array
    {
        if ($this->option('save-cli-settings') && ! $this->option('use-current-cli-settings')) {
            throw new RuntimeException('--save-cli-settings requires --use-current-cli-settings.');
        }
        $current = CliWorkflowExecutionScope::current();
        if ($this->option('use-current-cli-settings')) {
            return $current ?? throw new RuntimeException('Choose settings in the CLI before using --use-current-cli-settings.');
        }
        if (($task->cli_execution_scope ?? null) === null) {
            return $current;
        }
        try {
            $saved = is_array($task->cli_execution_scope) ? $task->cli_execution_scope
                : json_decode($task->cli_execution_scope, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Saved task execution settings are invalid; no alternate settings were selected.');
        }
        CliWorkflowExecutionScope::validateCaptured($saved);

        return $saved;
    }

    private function taskPrompt(object $task, GenealogyCleanRoomGuidanceService $cleanRoomGuidance, array $permissions): string
    {
        $personLine = $task->person_id
            ? "Anchor person ID: {$task->person_id}\n"
            : "Anchor person ID: none; stay inside the task scope.\n";
        $relatedPeople = $this->decodeTaskJson($task->related_people_used ?? null);
        $sourcesChecked = $this->decodeTaskJson($task->sources_checked ?? null);
        $taskParameters = $this->decodeTaskJson($task->parameters ?? null);

        return "Bounded non-face genealogy research task for tree {$task->tree_id}.\n"
            ."Existing task ID: {$task->id}\n"
            .$personLine
            ."Task type: {$task->task_type}\n"
            ."Research question: {$task->research_question}\n"
            .'Selection reason: '.($task->selection_reason ?? 'none supplied')."\n"
            .'Scope reason: '.($task->scope_reason ?? 'none supplied')."\n"
            .'Existing evidence: '.($task->evidence_summary ?? 'none supplied')."\n"
            .'Known conflicts: '.($task->conflicts_found ?? 'none supplied')."\n"
            .'Related person IDs: '.($relatedPeople !== [] ? implode(', ', $relatedPeople) : 'none')."\n"
            .'Sources already checked: '.($sourcesChecked !== [] ? implode(', ', $sourcesChecked) : 'none')."\n"
            .'Task parameters: '.($taskParameters !== [] ? json_encode($taskParameters, JSON_UNESCAPED_SLASHES) : 'none')."\n\n"
            ."Rules:\n"
            ."- This is not a face recognition, face cluster, or face/person-linking task.\n"
            ."- Do not use face clusters or photo identity as evidence.\n"
            .'- Current operator permission flags: '.json_encode($permissions, JSON_THROW_ON_ERROR).". Only enabled actions may proceed, through existing tool gates, approved apply paths, and required review packets.\n"
            ."- Same-name web hits are leads only until a source provides an identity bridge.\n"
            ."- FT4 tasks should prefer source-backed expansion and fill-ins for blank facts, relationships, source gaps, and media gaps; submit packets only when source text identifies the target beyond a shared name.\n"
            ."- If a source exposes a stable public/operator-owned image, PDF, document, OCR/text export, or repository object, use available capture/download tools and cite/link the resulting media. If capture needs visible Chrome/manual download, report it in SOURCE_TOOL_GAPS with the exact artifact needed.\n"
            ."- Use source-backed public/API searches and log negative searches as evidence.\n"
            ."- Document searches in the final outcome and use search-coverage tools when available; do not pass this genealogy_research_tasks ID to GPS-only logging tools unless the runtime supplies a compatible GPS task ID.\n\n"
            .$cleanRoomGuidance->buildPromptBlock()
            ."\n"
            ."At the END of your final response, include these exact lines:\n"
            ."OUTCOME_STATE: completed|deferred|requeue|needs_human_review\n"
            ."OUTCOME_REASON: <concise reason>\n"
            ."SCOPE_REASON: <who else was included and why, or none>\n"
            ."RELATED_PEOPLE_USED: <comma-separated people or none>\n"
            ."SOURCES_CHECKED: <comma-separated source classes>\n"
            ."EVIDENCE_SUMMARY: <concise evidence-backed summary>\n"
            ."CONFLICTS_FOUND: <concise conflict summary or none>\n"
            ."EVIDENCE_PACKETS: <JSON array as specified above, or none>\n"
            ."HYPOTHESIS_LEDGER: <JSON array as specified above, or none>\n"
            ."AGENT_ACTIVITY_TRACE: <JSON array as specified above, or none>\n"
            ."SOURCE_TOOL_GAPS: <JSON array as specified above, or none>\n"
            .'REVIEW_GATES: <JSON object as specified above, or none>';
    }

    /** @return array<int|string, mixed> */
    private function decodeTaskJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function parseOutcome(string $response, GenealogyCleanRoomGuidanceService $cleanRoomGuidance): array
    {
        $extract = function (string $label) use ($response): ?string {
            if (preg_match('/^'.preg_quote($label, '/').':\s*(.+)$/mi', $response, $matches)) {
                return trim($matches[1]);
            }

            return null;
        };

        $splitList = static function (?string $value): array {
            if (! $value || strtolower($value) === 'none') {
                return [];
            }

            return array_values(array_filter(array_map('trim', explode(',', $value))));
        };

        return [
            'outcome_state' => $extract('OUTCOME_STATE'),
            'outcome_reason' => $extract('OUTCOME_REASON'),
            'scope_reason' => $extract('SCOPE_REASON'),
            'related_people_used' => $splitList($extract('RELATED_PEOPLE_USED')),
            'sources_checked' => $splitList($extract('SOURCES_CHECKED')),
            'evidence_summary' => $extract('EVIDENCE_SUMMARY'),
            'conflicts_found' => $extract('CONFLICTS_FOUND'),
            'research_guidance' => $cleanRoomGuidance->parseFinalMarkers($response),
        ];
    }

    /**
     * @return array{person_creation:bool,canonical_facts:bool,downloads:bool,writeback:bool,scheduled_enablement:bool,unanchored:bool}
     */
    private function permissionFlags(): array
    {
        return [
            'person_creation' => (bool) $this->option('confirm-person-creation'),
            'canonical_facts' => (bool) $this->option('confirm-canonical-facts'),
            'downloads' => (bool) $this->option('confirm-downloads'),
            'writeback' => (bool) $this->option('confirm-writeback'),
            'scheduled_enablement' => (bool) $this->option('confirm-scheduled-enablement'),
            'unanchored' => (bool) $this->option('confirm-unanchored'),
        ];
    }

    /**
     * @param  array{person_creation:bool,canonical_facts:bool,downloads:bool,writeback:bool,scheduled_enablement:bool,unanchored:bool}|null  $permissions
     */
    private function blockedTaskReason(object $task, ?array $permissions = null, ?int $timeoutMinutes = null): ?string
    {
        $question = strtolower((string) ($task->research_question ?? ''));
        if (preg_match('/\b(face|face[- ]?cluster|facial|photo identity|photo[- ]?person|same person in (?:the )?photo|picture cluster)\b/', $question) === 1) {
            return 'face_or_photo_identity_task_blocked';
        }

        if ($task->person_id === null) {
            if ($permissions !== null && ! $permissions['unanchored']) {
                return 'unanchored_task_requires_confirm_unanchored';
            }

            $minimumTimeoutMinutes = $this->minimumUnanchoredTimeoutMinutes();
            if ($timeoutMinutes !== null && $timeoutMinutes < $minimumTimeoutMinutes) {
                return "unanchored_task_requires_timeout_at_least_{$minimumTimeoutMinutes}_minutes";
            }
        }

        return null;
    }

    private function minimumUnanchoredTimeoutMinutes(): int
    {
        $reportReserveSeconds = (int) config('agents.hybrid_report_reserve_seconds', 300);

        return max(8, (int) ceil(($reportReserveSeconds + 180) / 60));
    }

    private function personName(int $treeId, int $personId): string
    {
        $person = DB::selectOne(
            'SELECT given_name, surname
             FROM genealogy_persons
             WHERE tree_id = ?
               AND id = ?
             LIMIT 1',
            [$treeId, $personId]
        );

        $name = trim((string) (($person->given_name ?? '').' '.($person->surname ?? '')));

        return $name !== '' ? $name : "Person #{$personId}";
    }

    /**
     * @return array{total_count:int,reviewable_count:int,rejected_count:int}|null
     */
    private function reviewOutcomeCounts(int $personId, string $runStartedAt): ?array
    {
        try {
            $rows = DB::select(
                "SELECT status, COUNT(*) AS count
                 FROM agent_review_queue
                 WHERE agent_id = 'genealogy-records'
                   AND review_type = 'genealogy_finding'
                   AND JSON_EXTRACT(details, '$.person_id') = ?
                   AND (created_at >= ? OR updated_at >= ?)
                 GROUP BY status",
                [$personId, $runStartedAt, $runStartedAt]
            );
        } catch (\Throwable) {
            return null;
        }

        $counts = [
            'total_count' => 0,
            'reviewable_count' => 0,
            'rejected_count' => 0,
        ];
        foreach ($rows as $row) {
            $count = (int) ($row->count ?? 0);
            $status = (string) ($row->status ?? '');
            $counts['total_count'] += $count;
            if (in_array($status, ['pending', 'reviewed', 'approved'], true)) {
                $counts['reviewable_count'] += $count;
            }
            if ($status === 'rejected') {
                $counts['rejected_count'] += $count;
            }
        }

        return $counts;
    }

    private function emit(array $payload, int $exitCode = 0): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        if (($payload['status'] ?? null) === 'blocked') {
            $this->error($payload['error']);

            return $exitCode;
        }

        $this->info("Selected {$payload['selected_count']} task(s) in {$payload['mode']} mode.");
        foreach ($payload['tasks'] as $task) {
            $this->line("#{$task['id']} {$task['priority']} {$task['status']}: {$task['question_preview']}");
        }
        foreach ($payload['results'] as $result) {
            $outcome = $result['outcome_state'] ?? $result['reason'] ?? 'n/a';
            $this->line("#{$result['task_id']}: {$result['status']} / {$outcome}");
        }

        return $exitCode;
    }
}
