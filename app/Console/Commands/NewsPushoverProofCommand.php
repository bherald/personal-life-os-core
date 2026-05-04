<?php

namespace App\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NewsPushoverProofCommand extends Command
{
    protected $signature = 'news:pushover-proof
                            {--workflow=news_brief : Workflow name to inspect}
                            {--run-id= : Inspect a specific workflow_runs.id instead of the latest completed natural run}
                            {--json : Output machine-readable JSON}
                            {--compact : Output compact key evidence only}';

    protected $description = 'Read-only Pushover delivery proof for natural news workflow runs';

    public function handle(): int
    {
        $workflow = trim((string) $this->option('workflow'));
        if ($workflow === '') {
            return $this->finish($this->emptyReport('fail', ['--workflow cannot be empty.']));
        }

        $runIdOption = $this->option('run-id');
        $explicitRunId = null;
        if ($runIdOption !== null && trim((string) $runIdOption) !== '') {
            $runId = trim((string) $runIdOption);
            if (! ctype_digit($runId)) {
                return $this->finish($this->emptyReport('fail', ['Invalid --run-id; expected an integer workflow_runs.id.']));
            }

            $explicitRunId = (int) $runId;
        }

        $run = $this->findRun($workflow, $explicitRunId);
        if (! $run) {
            $message = $explicitRunId === null
                ? "No completed natural {$workflow} run was found."
                : "workflow_runs.id {$explicitRunId} was not found.";

            return $this->finish($this->emptyReport($explicitRunId === null ? 'inconclusive' : 'fail', [$message], $workflow));
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'status' => 'inconclusive',
            'run' => $this->summarizeRun($run),
            'pushover' => $this->summarizePushover($this->fetchPushoverExecutions((int) $run->id)),
            'status_reasons' => [
                'fail' => [],
                'inconclusive' => [],
            ],
        ];

        if ((string) $run->workflow_name !== $workflow) {
            $report['status_reasons']['fail'][] = "workflow_runs.id {$run->id} belongs to workflow '{$run->workflow_name}', not '{$workflow}'.";
        }

        [$status, $reasons] = $this->determineStatus($report);
        $report['status'] = $status;
        $report['status_reasons'] = $reasons;

        return $this->finish($report);
    }

    private function emptyReport(string $status, array $reasons, ?string $workflow = null): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'workflow' => $workflow,
            'run' => null,
            'pushover' => [
                'present' => false,
                'execution_ids' => [],
                'proof_state' => 'absent',
                'reason' => 'Pushover node was not found.',
            ],
            'status_reasons' => [
                'fail' => $status === 'fail' ? $reasons : [],
                'inconclusive' => $status === 'inconclusive' ? $reasons : [],
            ],
        ];
    }

    private function findRun(string $workflow, ?int $runId): ?object
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
            [$workflow, 'completed']
        );
    }

    private function fetchPushoverExecutions(int $runId): array
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
               AND (ne.node_type = ? OR ne.node_type LIKE ?)
             ORDER BY ne.node_order ASC, ne.id ASC, neo.id ASC',
            [$runId, 'PushoverNotify', '%\\PushoverNotify']
        );

        $executions = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! isset($executions[$id])) {
                $executions[$id] = [
                    'id' => $id,
                    'node_type' => (string) $row->node_type,
                    'node_order' => (int) $row->node_order,
                    'state' => $this->stringOrNull($row->state),
                    'duration_ms' => $this->intOrNull($row->duration_ms),
                    'timeout_seconds' => $this->intOrNull($row->timeout_seconds),
                    'timed_out' => $this->boolOrNull($row->timed_out),
                    'error_message' => $this->stringOrNull($row->error_message),
                    'executed_at' => $this->formatDate($row->executed_at),
                    'outputs' => [],
                ];

                $decodedExecutionOutput = $this->decodeOutputValue($row->execution_output);
                if (is_array($decodedExecutionOutput)) {
                    $executions[$id]['outputs'] = $decodedExecutionOutput;
                }
            }

            if ($row->output_id === null || $row->output_key === null) {
                continue;
            }

            $key = (string) $row->output_key;
            $executions[$id]['outputs'][$key] = $this->decodeOutputValue($row->output_value);
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

    private function summarizePushover(array $executions): array
    {
        if ($executions === []) {
            return [
                'present' => false,
                'execution_ids' => [],
                'proof_state' => 'absent',
                'reason' => 'Pushover node was not found.',
            ];
        }

        $last = $executions[array_key_last($executions)];
        $data = $this->arrayValue($last['outputs']['data'] ?? null);
        $meta = $this->arrayValue($last['outputs']['meta'] ?? null);
        [$error, $ignoredError] = $this->pushoverExecutionError($last, $data);

        $summary = [
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
            'part_numbers_sent' => $this->intList($data['part_numbers_sent'] ?? null),
            'part_numbers_suppressed' => $this->intList($data['part_numbers_suppressed'] ?? null),
            'part_numbers_failed' => $this->intList($data['part_numbers_failed'] ?? null),
            'message_length' => $this->intOrNull($data['message_length'] ?? null),
            'has_url' => $this->boolOrNull($data['has_url'] ?? null),
            'inter_chunk_delay_seconds' => $this->intOrNull($data['inter_chunk_delay_seconds'] ?? null),
        ];

        [$summary['proof_state'], $summary['reason']] = $this->pushoverProofState($summary);

        return $summary;
    }

    private function determineStatus(array $report): array
    {
        $fail = $report['status_reasons']['fail'] ?? [];
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

        $pushover = $report['pushover'] ?? [];
        if (($pushover['proof_state'] ?? null) !== 'delivery_confirmed') {
            $inconclusive[] = (string) ($pushover['reason'] ?? 'Pushover notification delivery proof is inconclusive.');
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

    private function pushoverProofState(array $pushover): array
    {
        if (($pushover['present'] ?? false) !== true) {
            return ['absent', 'Pushover node was not found.'];
        }

        $error = $this->stringOrNull($pushover['error'] ?? null);
        if (($pushover['timed_out'] ?? null) === true || $this->isTimeoutError($error)) {
            return ['timeout_inconclusive', 'Pushover reported a timeout; notification delivery proof is inconclusive.'];
        }

        if ($error !== null) {
            return ['error_inconclusive', 'Pushover reported an error; notification delivery proof is inconclusive.'];
        }

        if (($pushover['notification_suppressed'] ?? null) === true) {
            return ['suppressed_inconclusive', 'Pushover notification was suppressed; notification delivery proof is inconclusive.'];
        }

        if (($pushover['notification_sent'] ?? null) === false) {
            return ['not_sent_inconclusive', 'Pushover did not confirm notification delivery.'];
        }

        if (($pushover['notification_sent'] ?? null) === true) {
            $partsSent = $this->intOrNull($pushover['parts_sent'] ?? null);
            $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
            if ($partsSent !== null && $totalParts !== null && $partsSent !== $totalParts) {
                return ['partial_delivery_inconclusive', 'Pushover did not confirm all message parts were sent.'];
            }

            return ['delivery_confirmed', 'Pushover reported notification delivery.'];
        }

        return ['unknown', 'Pushover delivery metadata was not found.'];
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
            'pushover' => [
                'proof_state' => $report['pushover']['proof_state'] ?? null,
                'reason' => $report['pushover']['reason'] ?? null,
                'state' => $report['pushover']['state'] ?? null,
                'notification_sent' => $report['pushover']['notification_sent'] ?? null,
                'notification_suppressed' => $report['pushover']['notification_suppressed'] ?? null,
                'parts_sent' => $report['pushover']['parts_sent'] ?? null,
                'total_parts' => $report['pushover']['total_parts'] ?? null,
                'part_numbers_sent' => $report['pushover']['part_numbers_sent'] ?? [],
                'part_numbers_failed' => $report['pushover']['part_numbers_failed'] ?? [],
                'inter_chunk_delay_seconds' => $report['pushover']['inter_chunk_delay_seconds'] ?? null,
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

    private function writeCompactHumanReport(array $report): void
    {
        $this->line('News Pushover proof: '.strtoupper((string) $report['status']));

        if (! empty($report['run'])) {
            $run = $report['run'];
            $this->line("Run: #{$run['id']} {$run['workflow_name']} status={$run['status']} natural=".var_export($run['natural'], true)
                ." started={$run['started_at']} completed={$run['completed_at']}");
        }

        $pushover = $report['pushover'];
        $this->line('Pushover proof: state='.$pushover['proof_state']
            .' reason='.$pushover['reason']
            .' sent='.var_export($pushover['notification_sent'], true)
            .' suppressed='.var_export($pushover['notification_suppressed'], true)
            .' parts='.($pushover['parts_sent'] ?? 'n/a').'/'.($pushover['total_parts'] ?? 'n/a')
            .' failed_parts='.implode(',', $pushover['part_numbers_failed'] ?? [])
            .' delay_s='.($pushover['inter_chunk_delay_seconds'] ?? 'n/a')
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
        $this->line('News Pushover proof: '.strtoupper((string) $report['status']));

        if (! empty($report['run'])) {
            $run = $report['run'];
            $this->line("Run: #{$run['id']} {$run['workflow_name']} status={$run['status']} started={$run['started_at']} completed={$run['completed_at']}");
        }

        $pushover = $report['pushover'];
        $this->line('Pushover: present='.(($pushover['present'] ?? false) ? 'yes' : 'no')
            .' state='.($pushover['proof_state'] ?? 'unknown')
            .' sent='.var_export($pushover['notification_sent'] ?? null, true)
            .' suppressed='.var_export($pushover['notification_suppressed'] ?? null, true)
            .' parts='.($pushover['parts_sent'] ?? 'n/a').'/'.($pushover['total_parts'] ?? 'n/a')
            .' failed_parts='.implode(',', $pushover['part_numbers_failed'] ?? [])
            .' delay_s='.($pushover['inter_chunk_delay_seconds'] ?? 'n/a')
            .' timeout='.var_export($pushover['timed_out'] ?? null, true)
            .' duration_ms='.($pushover['duration_ms'] ?? 'n/a'));

        foreach (($report['status_reasons']['fail'] ?? []) as $reason) {
            $this->line('FAIL: '.$reason);
        }

        foreach (($report['status_reasons']['inconclusive'] ?? []) as $reason) {
            $this->line('INCONCLUSIVE: '.$reason);
        }
    }

    private function decodeOutputValue(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
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

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item): ?int => is_numeric($item) ? (int) $item : null, $value),
            fn (?int $item): bool => $item !== null
        ));
    }

    private function isTimeoutError(?string $error): bool
    {
        if ($error === null) {
            return false;
        }

        return preg_match('/\b(Node timeout|timed out|timeout)\b/iu', $error) === 1;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value === null ? null : (string) $value;
    }
}
