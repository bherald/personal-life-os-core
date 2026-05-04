<?php

namespace App\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NewsBiasTagsAuditCommand extends Command
{
    protected $signature = 'news:bias-tags-audit
                            {--json : Output machine-readable JSON}
                            {--compact : Output compact key evidence only}
                            {--run-id= : Inspect a specific workflow_runs.id instead of the latest completed natural news_brief run}';

    protected $description = 'Audit stored news_brief political-bias enrichment and visible tags without running workflows';

    public function handle(): int
    {
        $runIdOption = $this->option('run-id');
        $explicitRunId = null;

        if ($runIdOption !== null && trim((string) $runIdOption) !== '') {
            $runId = trim((string) $runIdOption);
            if (! ctype_digit($runId)) {
                return $this->finish([
                    'generated_at' => now()->toIso8601String(),
                    'status' => 'fail',
                    'run' => null,
                    'status_reasons' => [
                        'fail' => ['Invalid --run-id; expected an integer workflow_runs.id.'],
                        'inconclusive' => [],
                    ],
                ]);
            }

            $explicitRunId = (int) $runId;
        }

        $run = $this->findRun($explicitRunId);
        if (! $run) {
            return $this->finish([
                'generated_at' => now()->toIso8601String(),
                'status' => $explicitRunId === null ? 'inconclusive' : 'fail',
                'run' => null,
                'status_reasons' => [
                    'fail' => $explicitRunId === null ? [] : ["workflow_runs.id {$explicitRunId} was not found."],
                    'inconclusive' => $explicitRunId === null
                        ? ['No completed natural news_brief run was found.']
                        : [],
                ],
            ]);
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'status' => 'inconclusive',
            'run' => $this->summarizeRun($run),
            'bias_rating_enrich' => [
                'present' => false,
                'execution_ids' => [],
                'states' => [],
                'errors' => [],
                'enriched_count' => null,
                'distribution' => null,
            ],
            'batch_processor' => [
                'present' => false,
                'execution_ids' => [],
                'states' => [],
                'data_present' => false,
                'error' => null,
                'batch_count' => null,
                'total_articles' => null,
                'total_enriched' => null,
                'fallback_batches' => null,
                'ai_failed_batches' => null,
                'sanitized_batches' => null,
            ],
            'visible_tags' => [
                'present' => false,
                'count' => 0,
                'line_count' => 0,
                'text_length' => 0,
                'source' => 'BatchProcessor.data',
            ],
            'prompt_leak' => [
                'detected' => false,
                'markers' => [],
            ],
            'pushover' => [
                'present' => false,
                'execution_ids' => [],
            ],
            'status_reasons' => [
                'fail' => [],
                'inconclusive' => [],
            ],
        ];

        if (($run->workflow_name ?? null) !== 'news_brief') {
            $report['status'] = 'fail';
            $report['status_reasons']['fail'][] = "workflow_runs.id {$run->id} belongs to workflow '{$run->workflow_name}', not news_brief.";

            return $this->finish($report);
        }

        $executions = $this->fetchNodeExecutions((int) $run->id);
        $biasExecutions = $this->filterExecutionsByType($executions, 'BiasRatingEnrich');
        $batchExecutions = $this->filterExecutionsByType($executions, 'BatchProcessor');
        $pushoverExecutions = $this->filterExecutionsByType($executions, 'PushoverNotify');

        $report['bias_rating_enrich'] = $this->summarizeBiasRatingEnrich($biasExecutions);

        $batchSummary = $this->summarizeBatchProcessor($batchExecutions);
        $visibleText = $batchSummary['_visible_text'];
        unset($batchSummary['_visible_text']);
        $report['batch_processor'] = $batchSummary;
        $report['visible_tags'] = $this->summarizeVisibleTags($visibleText);
        $report['prompt_leak'] = $this->summarizePromptLeak($visibleText);
        $report['pushover'] = $this->summarizePushover($pushoverExecutions);

        [$status, $reasons] = $this->determineStatus($report);
        $report['status'] = $status;
        $report['status_reasons'] = $reasons;

        return $this->finish($report);
    }

    private function findRun(?int $runId): ?object
    {
        if ($runId !== null) {
            return DB::selectOne(
                'SELECT
                    wr.id,
                    wr.workflow_id,
                    wr.status,
                    wr.error_message,
                    wr.started_at,
                    wr.completed_at,
                    wr.parent_run_id,
                    wr.depth,
                    w.name AS workflow_name
                 FROM workflow_runs wr
                 INNER JOIN workflows w ON w.id = wr.workflow_id
                 WHERE wr.id = ?
                 LIMIT 1',
                [$runId]
            );
        }

        return DB::selectOne(
            'SELECT
                wr.id,
                wr.workflow_id,
                wr.status,
                wr.error_message,
                wr.started_at,
                wr.completed_at,
                wr.parent_run_id,
                wr.depth,
                w.name AS workflow_name
             FROM workflow_runs wr
             INNER JOIN workflows w ON w.id = wr.workflow_id
             WHERE w.name = ?
               AND wr.status = ?
               AND wr.parent_run_id IS NULL
               AND (wr.depth IS NULL OR wr.depth = 0)
             ORDER BY wr.completed_at DESC, wr.started_at DESC, wr.id DESC
             LIMIT 1',
            ['news_brief', 'completed']
        );
    }

    private function fetchNodeExecutions(int $runId): array
    {
        $rows = DB::select(
            'SELECT
                ne.id,
                ne.node_type,
                ne.node_order,
                ne.state,
                ne.duration_ms,
                ne.timeout_seconds,
                ne.timed_out,
                ne.error_message,
                ne.executed_at,
                ne.`output` AS execution_output,
                neo.id AS output_id,
                neo.output_stream,
                neo.output_key,
                neo.output_value
             FROM node_executions ne
             LEFT JOIN node_execution_outputs neo ON neo.node_execution_id = ne.id
             WHERE ne.run_id = ?
             ORDER BY ne.node_order ASC, ne.id ASC, neo.id ASC',
            [$runId]
        );

        $executions = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! isset($executions[$id])) {
                $executions[$id] = [
                    'id' => $id,
                    'node_type' => (string) $row->node_type,
                    'node_order' => (int) $row->node_order,
                    'state' => $row->state,
                    'duration_ms' => $this->intOrNull($row->duration_ms),
                    'timeout_seconds' => $this->intOrNull($row->timeout_seconds),
                    'timed_out' => $this->boolOrNull($row->timed_out),
                    'error_message' => $this->stringOrNull($row->error_message),
                    'executed_at' => $this->formatDate($row->executed_at),
                    'outputs' => [],
                    'streams' => [],
                ];

                $decodedExecutionOutput = $this->decodeOutputValue($row->execution_output);
                if (is_array($decodedExecutionOutput)) {
                    $executions[$id]['outputs'] = $decodedExecutionOutput;
                }
            }

            if ($row->output_id === null || $row->output_key === null) {
                continue;
            }

            $stream = $row->output_stream ?: 'default';
            $key = (string) $row->output_key;
            $value = $this->decodeOutputValue($row->output_value);

            $executions[$id]['streams'][$stream][$key] = $value;
            if ($stream === 'default' || ! array_key_exists($key, $executions[$id]['outputs'])) {
                $executions[$id]['outputs'][$key] = $value;
            }
        }

        return array_values($executions);
    }

    private function summarizeRun(object $run): array
    {
        $depth = $run->depth === null ? null : (int) $run->depth;
        $parentRunId = $run->parent_run_id === null ? null : (int) $run->parent_run_id;

        return [
            'id' => (int) $run->id,
            'workflow_id' => (int) $run->workflow_id,
            'workflow_name' => (string) $run->workflow_name,
            'status' => (string) $run->status,
            'error_message' => $this->stringOrNull($run->error_message),
            'started_at' => $this->formatDate($run->started_at),
            'completed_at' => $this->formatDate($run->completed_at),
            'parent_run_id' => $parentRunId,
            'depth' => $depth,
            'natural' => $parentRunId === null && ($depth === null || $depth === 0),
        ];
    }

    private function summarizeBiasRatingEnrich(array $executions): array
    {
        $distribution = [];
        $enrichedCount = 0;
        $hasEnrichedCount = false;
        $errors = [];

        foreach ($executions as $execution) {
            $meta = $this->arrayValue($execution['outputs']['meta'] ?? null);
            $count = $this->intOrNull($meta['enriched_count'] ?? null);
            if ($count !== null) {
                $hasEnrichedCount = true;
                $enrichedCount += $count;
            }

            $nodeDistribution = $this->arrayValue($meta['bias_distribution'] ?? null);
            foreach ($nodeDistribution as $rating => $value) {
                $distribution[$rating] = ($distribution[$rating] ?? 0) + (int) $value;
            }

            $error = $this->executionError($execution);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return [
            'present' => count($executions) > 0,
            'execution_ids' => array_map(fn (array $execution): int => $execution['id'], $executions),
            'states' => array_values(array_unique(array_map(fn (array $execution): ?string => $execution['state'], $executions))),
            'errors' => array_values(array_unique($errors)),
            'enriched_count' => $hasEnrichedCount ? $enrichedCount : null,
            'distribution' => $distribution === [] ? null : $distribution,
        ];
    }

    private function summarizeBatchProcessor(array $executions): array
    {
        $summary = [
            'present' => count($executions) > 0,
            'execution_ids' => array_map(fn (array $execution): int => $execution['id'], $executions),
            'states' => array_values(array_unique(array_map(fn (array $execution): ?string => $execution['state'], $executions))),
            'data_present' => false,
            'error' => null,
            'batch_count' => null,
            'total_articles' => null,
            'total_enriched' => null,
            'fallback_batches' => null,
            'ai_failed_batches' => null,
            'sanitized_batches' => null,
            '_visible_text' => '',
        ];

        $visibleText = [];
        $errors = [];

        foreach ($executions as $execution) {
            $outputs = $execution['outputs'];
            $data = $outputs['data'] ?? $outputs['formatted_text'] ?? $outputs['value'] ?? null;
            if ($this->dataPresent($data)) {
                $summary['data_present'] = true;
            }

            array_push($visibleText, ...$this->visibleTextCandidates($data));
            if (isset($outputs['formatted_text'])) {
                array_push($visibleText, ...$this->visibleTextCandidates($outputs['formatted_text']));
            }

            $meta = $this->arrayValue($outputs['meta'] ?? null);
            foreach (['batch_count', 'total_articles', 'total_enriched', 'fallback_batches', 'ai_failed_batches', 'sanitized_batches'] as $key) {
                $this->addNullableInt($summary, $key, $meta[$key] ?? null);
            }

            $error = $this->executionError($execution);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        $summary['error'] = $errors === [] ? null : implode('; ', array_values(array_unique($errors)));
        $summary['_visible_text'] = trim(implode("\n", array_filter($visibleText, fn (string $text): bool => trim($text) !== '')));

        return $summary;
    }

    private function summarizeVisibleTags(string $text): array
    {
        $count = 0;
        if ($text !== '') {
            $count = preg_match_all(
                '/(\[[^\]]*(?:Left-Center|Right-Center|Left|Center|Right)[^\]]*\]|\bbias:\s*(?:left-center|right-center|left|center|right)\b)/iu',
                $text
            );
            $count = $count === false ? 0 : $count;
        }

        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $lineCount = count(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        return [
            'present' => $count > 0,
            'count' => $count,
            'line_count' => $lineCount,
            'text_length' => strlen($text),
            'source' => 'BatchProcessor.data',
        ];
    }

    private function summarizePromptLeak(string $text): array
    {
        $patterns = [
            'article_data_marker' => '/\bARTICLE DATA (START|END)\b/iu',
            'format_requirements' => '/\bFORMAT REQUIREMENTS:/iu',
            'process_exactly' => '/\bProcess exactly \d+ articles\b/iu',
            'return_only_instruction' => '/\bReturn only the final formatted article lines\b/iu',
            'reasoning_prefix' => '/^(?:we are given|we need to|the user asks|the task is|reasoning:|analysis:)/imu',
            'batch_instruction' => '/\bThis is batch \d+ of \d+\b/iu',
        ];

        $markers = [];
        foreach ($patterns as $name => $pattern) {
            if ($text !== '' && preg_match($pattern, $text) === 1) {
                $markers[] = $name;
            }
        }

        return [
            'detected' => $markers !== [],
            'markers' => $markers,
        ];
    }

    private function summarizePushover(array $executions): array
    {
        if ($executions === []) {
            return [
                'present' => false,
                'execution_ids' => [],
            ];
        }

        $last = $executions[array_key_last($executions)];
        $data = $this->arrayValue($last['outputs']['data'] ?? null);
        $meta = $this->arrayValue($last['outputs']['meta'] ?? null);
        [$error, $ignoredError] = $this->pushoverExecutionError($last, $data);

        return [
            'present' => true,
            'execution_ids' => array_map(fn (array $execution): int => $execution['id'], $executions),
            'state' => $last['state'],
            'duration_ms' => $last['duration_ms'] ?? null,
            'timeout_seconds' => $last['timeout_seconds'] ?? null,
            'timed_out' => $last['timed_out'] ?? null,
            'error' => $error,
            'ignored_error' => $ignoredError,
            'provider' => $this->stringOrNull($meta['provider'] ?? null),
            'priority' => $this->intOrNull($meta['priority'] ?? null),
            'format_type' => $this->stringOrNull($data['format_type'] ?? $meta['format_type'] ?? null),
            'notification_sent' => $this->boolOrNull($data['notification_sent'] ?? null),
            'notification_suppressed' => $this->boolOrNull($data['notification_suppressed'] ?? null),
            'total_parts' => $this->intOrNull($data['total_parts'] ?? null),
            'parts_sent' => $this->intOrNull($data['parts_sent'] ?? null),
            'parts_suppressed' => $this->intOrNull($data['parts_suppressed'] ?? null),
            'message_length' => $this->intOrNull($data['message_length'] ?? null),
            'has_url' => $this->boolOrNull($data['has_url'] ?? null),
        ];
    }

    private function determineStatus(array $report): array
    {
        $fail = [];
        $inconclusive = [];
        $run = $report['run'];

        if ($run['status'] === 'failed') {
            $fail[] = 'Workflow run status is failed.';
        } elseif ($run['status'] !== 'completed') {
            $inconclusive[] = "Workflow run status is {$run['status']}, not completed.";
        }

        if (! $run['natural']) {
            $inconclusive[] = 'Workflow run is not a natural top-level run.';
        }

        $batch = $report['batch_processor'];
        if (! $batch['present']) {
            $inconclusive[] = 'No BatchProcessor execution was found for this run.';
        } else {
            if (! $batch['data_present']) {
                $inconclusive[] = 'BatchProcessor output data was not found.';
            }

            if ($batch['error'] !== null) {
                $fail[] = 'BatchProcessor reported an error.';
            }
        }

        $structuredEnrichmentCount = $this->structuredEnrichmentCount($report);
        if ($structuredEnrichmentCount === null || $structuredEnrichmentCount <= 0) {
            $inconclusive[] = 'No positive structured bias enrichment count was found.';
        }

        if (! $report['visible_tags']['present']) {
            if ($structuredEnrichmentCount !== null && $structuredEnrichmentCount > 0) {
                $fail[] = 'Structured bias enrichment exists, but no visible political-bias tags were found.';
            } else {
                $inconclusive[] = 'No visible political-bias tags were found.';
            }
        }

        if ($report['prompt_leak']['detected']) {
            $fail[] = 'Prompt leak markers were found in BatchProcessor output.';
        }

        $pushover = $report['pushover'] ?? [];
        if (($pushover['present'] ?? false) === true) {
            $pushoverError = $this->stringOrNull($pushover['error'] ?? null);
            if (($pushover['timed_out'] ?? null) === true || $this->isTimeoutError($pushoverError)) {
                $inconclusive[] = 'Pushover reported a timeout; notification delivery proof is inconclusive.';
            } elseif ($pushoverError !== null) {
                $inconclusive[] = 'Pushover reported an error; notification delivery proof is inconclusive.';
            } elseif (($pushover['notification_suppressed'] ?? null) === true) {
                $inconclusive[] = 'Pushover notification was suppressed; notification delivery proof is inconclusive.';
            } elseif (($pushover['notification_sent'] ?? null) === false) {
                $inconclusive[] = 'Pushover did not confirm notification delivery.';
            }
        }

        $status = $fail !== [] ? 'fail' : ($inconclusive !== [] ? 'inconclusive' : 'pass');

        return [
            $status,
            [
                'fail' => array_values(array_unique($fail)),
                'inconclusive' => array_values(array_unique($inconclusive)),
            ],
        ];
    }

    private function structuredEnrichmentCount(array $report): ?int
    {
        $counts = [];
        foreach ([
            $report['bias_rating_enrich']['enriched_count'] ?? null,
            $report['batch_processor']['total_enriched'] ?? null,
        ] as $count) {
            if (is_int($count)) {
                $counts[] = $count;
            }
        }

        return $counts === [] ? null : max($counts);
    }

    private function filterExecutionsByType(array $executions, string $nodeType): array
    {
        return array_values(array_filter(
            $executions,
            fn (array $execution): bool => $this->nodeTypeMatches($execution['node_type'], $nodeType)
        ));
    }

    private function nodeTypeMatches(string $actual, string $expected): bool
    {
        $normalized = str_replace('\\', '/', $actual);

        return $actual === $expected
            || str_ends_with($actual, $expected)
            || str_ends_with($normalized, '/'.$expected);
    }

    private function executionError(array $execution): ?string
    {
        $outputs = $execution['outputs'];
        $meta = $this->arrayValue($outputs['meta'] ?? null);

        foreach ([
            $outputs['error'] ?? null,
            $meta['error_message'] ?? null,
            $execution['error_message'] ?? null,
        ] as $candidate) {
            $error = $this->stringOrNull($candidate);
            if ($error !== null) {
                return $error;
            }
        }

        if (($execution['state'] ?? null) === 'failed') {
            return 'Node execution state is failed.';
        }

        return null;
    }

    private function pushoverExecutionError(array $execution, array $data): array
    {
        $outputs = $execution['outputs'];
        $meta = $this->arrayValue($outputs['meta'] ?? null);
        $ignoredError = null;

        foreach ([
            'output' => $outputs['error'] ?? null,
            'meta' => $meta['error_message'] ?? null,
            'execution' => $execution['error_message'] ?? null,
        ] as $source => $candidate) {
            $error = $this->stringOrNull($candidate);
            if ($error === null) {
                continue;
            }

            if (
                $source === 'output'
                && $this->isTimeoutError($error)
                && $this->isSuccessfulShortPushoverExecution($execution, $data)
            ) {
                $ignoredError = $error;

                continue;
            }

            return [$error, $ignoredError];
        }

        if (($execution['state'] ?? null) === 'failed') {
            return ['Node execution state is failed.', $ignoredError];
        }

        return [null, $ignoredError];
    }

    private function isSuccessfulShortPushoverExecution(array $execution, array $data): bool
    {
        if (($execution['state'] ?? null) !== 'success') {
            return false;
        }

        if (($execution['timed_out'] ?? null) === true) {
            return false;
        }

        if ($this->boolOrNull($data['notification_sent'] ?? null) !== true) {
            return false;
        }

        $durationMs = $this->intOrNull($execution['duration_ms'] ?? null);
        $timeoutSeconds = $this->intOrNull($execution['timeout_seconds'] ?? null);
        if ($durationMs === null || $timeoutSeconds === null || $timeoutSeconds <= 0) {
            return false;
        }

        return $durationMs < ($timeoutSeconds * 1000);
    }

    private function isTimeoutError(?string $error): bool
    {
        if ($error === null) {
            return false;
        }

        return preg_match('/\b(Node timeout|timed out|timeout)\b/iu', $error) === 1;
    }

    private function visibleTextCandidates(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $candidates = [];
        foreach (['formatted_text', 'message', 'text', 'value', 'output'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                $candidates[] = $value[$key];
            }
        }

        if ($candidates === [] && array_is_list($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $candidates[] = $item;
                }
            }
        }

        return $candidates;
    }

    private function addNullableInt(array &$summary, string $key, mixed $value): void
    {
        $int = $this->intOrNull($value);
        if ($int === null) {
            return;
        }

        $summary[$key] = ($summary[$key] ?? 0) + $int;
    }

    private function decodeOutputValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function dataPresent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);

            return $string === '' ? null : $string;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($parsed) ? $parsed : null;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value === null ? null : (string) $value;
    }

    private function finish(array $report): int
    {
        $output = $this->option('compact') ? $this->compactReport($report) : $report;

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($this->option('compact')) {
            $this->writeCompactHumanReport($output);
        } else {
            $this->writeHumanReport($report);
        }

        return ($report['status'] ?? 'fail') === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    private function compactReport(array $report): array
    {
        [$pushoverState, $pushoverReason] = $this->pushoverProofState($report);

        return [
            'generated_at' => $report['generated_at'] ?? null,
            'status' => $report['status'] ?? 'fail',
            'run' => empty($report['run']) ? null : [
                'id' => $report['run']['id'] ?? null,
                'workflow_name' => $report['run']['workflow_name'] ?? null,
                'status' => $report['run']['status'] ?? null,
                'started_at' => $report['run']['started_at'] ?? null,
                'completed_at' => $report['run']['completed_at'] ?? null,
                'natural' => $report['run']['natural'] ?? null,
            ],
            'bias_tags' => [
                'enriched_count' => $this->structuredEnrichmentCount($report),
                'visible_count' => $report['visible_tags']['count'] ?? 0,
                'visible_line_count' => $report['visible_tags']['line_count'] ?? 0,
                'distribution' => $report['bias_rating_enrich']['distribution'] ?? null,
            ],
            'prompt_leak' => [
                'detected' => $report['prompt_leak']['detected'] ?? false,
                'markers' => $report['prompt_leak']['markers'] ?? [],
            ],
            'pushover' => [
                'proof_state' => $pushoverState,
                'reason' => $pushoverReason,
                'state' => $report['pushover']['state'] ?? null,
                'notification_sent' => $report['pushover']['notification_sent'] ?? null,
                'notification_suppressed' => $report['pushover']['notification_suppressed'] ?? null,
                'parts_sent' => $report['pushover']['parts_sent'] ?? null,
                'total_parts' => $report['pushover']['total_parts'] ?? null,
                'timed_out' => $report['pushover']['timed_out'] ?? null,
                'duration_ms' => $report['pushover']['duration_ms'] ?? null,
                'timeout_seconds' => $report['pushover']['timeout_seconds'] ?? null,
            ],
            'status_reasons' => $report['status_reasons'] ?? [
                'fail' => [],
                'inconclusive' => [],
            ],
        ];
    }

    private function pushoverProofState(array $report): array
    {
        $pushover = $report['pushover'] ?? [];
        if (($pushover['present'] ?? false) !== true) {
            return ['absent', 'Pushover node was not found.'];
        }

        $error = $this->stringOrNull($pushover['error'] ?? null);
        if (($pushover['timed_out'] ?? null) === true || $this->isTimeoutError($error)) {
            return ['timeout_inconclusive', $this->pushoverStatusReason($report, 'timeout')];
        }

        if ($error !== null) {
            return ['error_inconclusive', $this->pushoverStatusReason($report, 'error')];
        }

        if (($pushover['notification_suppressed'] ?? null) === true) {
            return ['suppressed_inconclusive', $this->pushoverStatusReason($report, 'suppressed')];
        }

        if (($pushover['notification_sent'] ?? null) === false) {
            return ['not_sent_inconclusive', $this->pushoverStatusReason($report, 'did not confirm')];
        }

        if (($pushover['notification_sent'] ?? null) === true) {
            return ['delivery_confirmed', 'Pushover reported notification delivery.'];
        }

        return ['unknown', 'Pushover delivery metadata was not found.'];
    }

    private function pushoverStatusReason(array $report, string $needle): string
    {
        foreach (($report['status_reasons']['inconclusive'] ?? []) as $reason) {
            if (str_contains(strtolower((string) $reason), strtolower($needle))) {
                return (string) $reason;
            }
        }

        return 'Pushover notification delivery proof is inconclusive.';
    }

    private function writeCompactHumanReport(array $report): void
    {
        $this->line('News bias tags compact audit: '.strtoupper((string) $report['status']));

        if (! empty($report['run'])) {
            $run = $report['run'];
            $this->line("Run: #{$run['id']} {$run['workflow_name']} status={$run['status']} natural=".var_export($run['natural'], true)
                ." started={$run['started_at']} completed={$run['completed_at']}");
        }

        $tags = $report['bias_tags'];
        $distribution = $tags['distribution'] === null ? 'n/a' : json_encode($tags['distribution'], JSON_UNESCAPED_SLASHES);
        $this->line('Bias tags: enriched='.($tags['enriched_count'] ?? 'n/a')
            ." visible={$tags['visible_count']}/{$tags['visible_line_count']}"
            .' distribution='.$distribution);

        $leak = $report['prompt_leak'];
        $markers = $leak['markers'] === [] ? 'none' : implode(',', $leak['markers']);
        $this->line('Prompt leak: detected='.($leak['detected'] ? 'yes' : 'no').' markers='.$markers);

        $pushover = $report['pushover'];
        $this->line('Pushover proof: state='.$pushover['proof_state']
            .' reason='.$pushover['reason']
            .' sent='.var_export($pushover['notification_sent'], true)
            .' suppressed='.var_export($pushover['notification_suppressed'], true)
            .' parts='.($pushover['parts_sent'] ?? 'n/a').'/'.($pushover['total_parts'] ?? 'n/a')
            .' timeout='.var_export($pushover['timed_out'], true)
            .' duration_ms='.($pushover['duration_ms'] ?? 'n/a'));

        foreach (($report['status_reasons']['fail'] ?? []) as $reason) {
            $this->line('FAIL: '.$reason);
        }

        foreach (($report['status_reasons']['inconclusive'] ?? []) as $reason) {
            $this->line('INCONCLUSIVE: '.$reason);
        }
    }

    private function writeHumanReport(array $report): void
    {
        $this->line('News bias tags audit: '.strtoupper((string) $report['status']));

        if (! empty($report['run'])) {
            $run = $report['run'];
            $this->line("Run: #{$run['id']} {$run['workflow_name']} status={$run['status']} started={$run['started_at']} completed={$run['completed_at']}");
        }

        if (isset($report['bias_rating_enrich'])) {
            $bias = $report['bias_rating_enrich'];
            $distribution = $bias['distribution'] === null ? 'n/a' : json_encode($bias['distribution'], JSON_UNESCAPED_SLASHES);
            $this->line('BiasRatingEnrich: present='.($bias['present'] ? 'yes' : 'no')
                .' enriched='.($bias['enriched_count'] ?? 'n/a')
                .' distribution='.$distribution);
        }

        if (isset($report['batch_processor'])) {
            $batch = $report['batch_processor'];
            $this->line('BatchProcessor: present='.($batch['present'] ? 'yes' : 'no')
                .' data='.($batch['data_present'] ? 'yes' : 'no')
                .' error='.($batch['error'] ?? 'none')
                .' total_enriched='.($batch['total_enriched'] ?? 'n/a'));
        }

        if (isset($report['visible_tags'])) {
            $tags = $report['visible_tags'];
            $this->line('Visible tags: present='.($tags['present'] ? 'yes' : 'no')
                ." count={$tags['count']} lines={$tags['line_count']}");
        }

        if (isset($report['prompt_leak'])) {
            $leak = $report['prompt_leak'];
            $markers = $leak['markers'] === [] ? 'none' : implode(',', $leak['markers']);
            $this->line('Prompt leak: detected='.($leak['detected'] ? 'yes' : 'no').' markers='.$markers);
        }

        if (! empty($report['pushover']['present'])) {
            $pushover = $report['pushover'];
            $this->line('Pushover: sent='.var_export($pushover['notification_sent'] ?? null, true)
                .' suppressed='.var_export($pushover['notification_suppressed'] ?? null, true)
                .' parts='.($pushover['parts_sent'] ?? 'n/a').'/'.($pushover['total_parts'] ?? 'n/a')
                .' format='.($pushover['format_type'] ?? 'n/a')
                .' timeout='.var_export($pushover['timed_out'] ?? null, true)
                .' duration_ms='.($pushover['duration_ms'] ?? 'n/a'));
        } else {
            $this->line('Pushover: not found');
        }

        foreach (($report['status_reasons']['fail'] ?? []) as $reason) {
            $this->line('FAIL: '.$reason);
        }

        foreach (($report['status_reasons']['inconclusive'] ?? []) as $reason) {
            $this->line('INCONCLUSIVE: '.$reason);
        }
    }
}
