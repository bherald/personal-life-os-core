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
            'current_pushover_config' => $this->summarizeCurrentPushoverConfig((int) $run->workflow_id),
            'status_reasons' => [
                'fail' => [],
                'inconclusive' => [],
            ],
        ];
        $report['pacing_config'] = $this->summarizePacingConfig(
            $report['pushover'],
            $report['current_pushover_config']
        );
        $report['part_number_proof'] = $this->summarizePartNumberProof($report['pushover']);
        $report['part_timestamp_proof'] = $this->summarizePartTimestampProof(
            $report['pushover'],
            $report['current_pushover_config']
        );
        $report['part_header_proof'] = $this->summarizePartHeaderProof(
            $report['pushover'],
            $report['current_pushover_config']
        );
        $report['part_content_proof'] = $this->summarizePartContentProof($report['pushover']);
        $report['client_display_proof'] = $this->summarizeClientDisplayProof(
            $report['pushover'],
            $report['part_number_proof'],
            $report['part_timestamp_proof'],
            $report['part_header_proof'],
            $report['part_content_proof']
        );

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
            'current_pushover_config' => null,
            'pacing_config' => null,
            'part_header_proof' => null,
            'client_display_proof' => null,
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
            'source_group' => $this->stringOrNull($data['source_group'] ?? $meta['source_group'] ?? null),
            'notification_sent' => $this->boolOrNull($data['notification_sent'] ?? null),
            'notification_suppressed' => $this->boolOrNull($data['notification_suppressed'] ?? null),
            'total_parts' => $this->intOrNull($data['total_parts'] ?? null),
            'parts_sent' => $this->intOrNull($data['parts_sent'] ?? null),
            'parts_suppressed' => $this->intOrNull($data['parts_suppressed'] ?? null),
            'part_numbers_sent' => $this->intList($data['part_numbers_sent'] ?? null),
            'part_numbers_suppressed' => $this->intList($data['part_numbers_suppressed'] ?? null),
            'part_numbers_failed' => $this->intList($data['part_numbers_failed'] ?? null),
            'part_timestamps_enabled' => $this->boolOrNull($data['part_timestamps_enabled'] ?? null),
            'part_timestamp_strategy' => $this->stringOrNull($data['part_timestamp_strategy'] ?? null),
            'part_timestamps' => $this->intMap($data['part_timestamps'] ?? null),
            'part_headers_enabled' => $this->boolOrNull($data['part_headers_enabled'] ?? null),
            'part_header_strategy' => $this->stringOrNull($data['part_header_strategy'] ?? null),
            'part_message_lengths' => $this->intMap($data['part_message_lengths'] ?? null),
            'part_message_hashes' => $this->stringMap($data['part_message_hashes'] ?? null),
            'part_response_requests' => $this->stringMap($data['part_response_requests'] ?? null),
            'message_length' => $this->intOrNull($data['message_length'] ?? null),
            'has_url' => $this->boolOrNull($data['has_url'] ?? null),
            'inter_chunk_delay_seconds' => $this->intOrNull($data['inter_chunk_delay_seconds'] ?? null),
        ];

        [$summary['proof_state'], $summary['reason']] = $this->pushoverProofState($summary);

        return $summary;
    }

    private function summarizeCurrentPushoverConfig(int $workflowId): ?array
    {
        $rows = DB::select(
            'SELECT
                wn.id,
                wn.node_order,
                wn.node_type,
                MAX(CASE WHEN wnc.config_key = ? THEN wnc.config_value END) AS inter_chunk_delay_seconds,
                MAX(CASE WHEN wnc.config_key = ? THEN wnc.config_value END) AS part_timestamps_enabled,
                MAX(CASE WHEN wnc.config_key = ? THEN wnc.config_value END) AS part_headers_enabled
             FROM workflow_nodes wn
             LEFT JOIN workflow_node_configs wnc
                ON wnc.workflow_node_id = wn.id
               AND wnc.config_key IN (?, ?, ?)
             WHERE wn.workflow_id = ?
               AND (wn.node_type = ? OR wn.node_type LIKE ?)
             GROUP BY wn.id, wn.node_order, wn.node_type
             ORDER BY wn.node_order ASC, wn.id ASC',
            [
                'inter_chunk_delay_seconds',
                'part_timestamps_enabled',
                'part_headers_enabled',
                'inter_chunk_delay_seconds',
                'part_timestamps_enabled',
                'part_headers_enabled',
                $workflowId,
                'PushoverNotify',
                '%\\PushoverNotify',
            ]
        );

        if ($rows === []) {
            return null;
        }

        $last = $rows[array_key_last($rows)];
        $configuredDelay = $this->intOrNull($last->inter_chunk_delay_seconds);

        return [
            'node_id' => (int) $last->id,
            'node_order' => (int) $last->node_order,
            'node_type' => (string) $last->node_type,
            'configured_inter_chunk_delay_seconds' => $configuredDelay,
            'effective_inter_chunk_delay_seconds' => $configuredDelay ?? 2,
            'configured_part_timestamps_enabled' => $this->boolOrNull($last->part_timestamps_enabled),
            'effective_part_timestamps_enabled' => $this->boolOrNull($last->part_timestamps_enabled) ?? false,
            'configured_part_headers_enabled' => $this->boolOrNull($last->part_headers_enabled),
            'effective_part_headers_enabled' => $this->boolOrNull($last->part_headers_enabled) ?? false,
        ];
    }

    private function summarizePacingConfig(array $pushover, ?array $currentConfig): array
    {
        if (($pushover['present'] ?? false) !== true) {
            return [
                'state' => 'pushover_absent',
                'reason' => 'Pushover node was not found in the inspected run.',
                'run_inter_chunk_delay_seconds' => null,
                'current_effective_inter_chunk_delay_seconds' => $currentConfig['effective_inter_chunk_delay_seconds'] ?? null,
                'matches_current_config' => null,
            ];
        }

        if ($currentConfig === null) {
            return [
                'state' => 'current_config_absent',
                'reason' => 'Current workflow Pushover node config was not found.',
                'run_inter_chunk_delay_seconds' => $pushover['inter_chunk_delay_seconds'] ?? null,
                'current_effective_inter_chunk_delay_seconds' => null,
                'matches_current_config' => null,
            ];
        }

        $runDelay = $this->intOrNull($pushover['inter_chunk_delay_seconds'] ?? null);
        $currentDelay = $this->intOrNull($currentConfig['effective_inter_chunk_delay_seconds'] ?? null);
        if ($runDelay === null) {
            return [
                'state' => 'run_metadata_missing',
                'reason' => 'Inspected run did not record inter_chunk_delay_seconds; use the next natural run for pacing proof.',
                'run_inter_chunk_delay_seconds' => null,
                'current_effective_inter_chunk_delay_seconds' => $currentDelay,
                'matches_current_config' => null,
            ];
        }

        if ($currentDelay === null) {
            return [
                'state' => 'current_config_unknown',
                'reason' => 'Current workflow Pushover inter-chunk delay could not be parsed.',
                'run_inter_chunk_delay_seconds' => $runDelay,
                'current_effective_inter_chunk_delay_seconds' => null,
                'matches_current_config' => null,
            ];
        }

        if ($runDelay === $currentDelay) {
            return [
                'state' => 'matches_current_config',
                'reason' => 'Inspected run recorded the current Pushover inter-chunk delay.',
                'run_inter_chunk_delay_seconds' => $runDelay,
                'current_effective_inter_chunk_delay_seconds' => $currentDelay,
                'matches_current_config' => true,
            ];
        }

        return [
            'state' => 'differs_from_current_config',
            'reason' => "Inspected run recorded delay {$runDelay}s, but current workflow config is {$currentDelay}s; use the next natural run for post-config pacing proof.",
            'run_inter_chunk_delay_seconds' => $runDelay,
            'current_effective_inter_chunk_delay_seconds' => $currentDelay,
            'matches_current_config' => false,
        ];
    }

    private function summarizePartNumberProof(array $pushover): array
    {
        if (($pushover['present'] ?? false) !== true) {
            return [
                'state' => 'pushover_absent',
                'reason' => 'Pushover node was not found in the inspected run.',
                'expected_part_numbers_sent' => [],
                'actual_part_numbers_sent' => [],
                'complete' => false,
                'exact_part_numbers_available' => false,
            ];
        }

        $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
        $partsSent = $this->intOrNull($pushover['parts_sent'] ?? null);
        $sentParts = $this->intList($pushover['part_numbers_sent'] ?? null);
        $failedParts = $this->intList($pushover['part_numbers_failed'] ?? null);

        if ($totalParts === null || $partsSent === null) {
            return [
                'state' => 'metadata_missing',
                'reason' => 'Pushover delivery counters were not recorded for this run.',
                'expected_part_numbers_sent' => [],
                'actual_part_numbers_sent' => $sentParts,
                'complete' => false,
                'exact_part_numbers_available' => $sentParts !== [],
            ];
        }

        $expectedParts = $totalParts > 0 ? range($totalParts, 1) : [];
        if ($totalParts <= 1 && $sentParts === []) {
            return [
                'state' => 'single_part',
                'reason' => 'Single-part notification; multipart part-number proof is not required.',
                'expected_part_numbers_sent' => $expectedParts,
                'actual_part_numbers_sent' => [],
                'complete' => $partsSent === $totalParts && $failedParts === [],
                'exact_part_numbers_available' => false,
            ];
        }

        if ($partsSent !== $totalParts || $failedParts !== []) {
            return [
                'state' => 'incomplete_delivery',
                'reason' => 'Pushover counters or failed-part metadata show incomplete multipart delivery.',
                'expected_part_numbers_sent' => $expectedParts,
                'actual_part_numbers_sent' => $sentParts,
                'complete' => false,
                'exact_part_numbers_available' => $sentParts !== [],
            ];
        }

        if ($sentParts === []) {
            return [
                'state' => 'part_numbers_missing',
                'reason' => 'Delivery counters confirm all parts, but exact part-number metadata was not recorded for this run.',
                'expected_part_numbers_sent' => $expectedParts,
                'actual_part_numbers_sent' => [],
                'complete' => true,
                'exact_part_numbers_available' => false,
            ];
        }

        if ($sentParts === $expectedParts) {
            return [
                'state' => 'exact_reverse_sequence',
                'reason' => 'Pushover output recorded every part in reverse send order so Part 1 appears newest in the client.',
                'expected_part_numbers_sent' => $expectedParts,
                'actual_part_numbers_sent' => $sentParts,
                'complete' => true,
                'exact_part_numbers_available' => true,
            ];
        }

        return [
            'state' => 'part_numbers_inconsistent',
            'reason' => 'Delivery counters confirm all parts, but recorded part numbers do not match the expected reverse send order.',
            'expected_part_numbers_sent' => $expectedParts,
            'actual_part_numbers_sent' => $sentParts,
            'complete' => true,
            'exact_part_numbers_available' => true,
        ];
    }

    private function summarizePartTimestampProof(array $pushover, ?array $currentConfig): array
    {
        if (($pushover['present'] ?? false) !== true) {
            return [
                'state' => 'pushover_absent',
                'reason' => 'Pushover node was not found in the inspected run.',
                'required' => false,
                'complete' => false,
            ];
        }

        $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
        if ($totalParts === null || $totalParts <= 1) {
            return [
                'state' => 'not_required',
                'reason' => 'Single-part or unknown-part notification; part timestamp proof is not required.',
                'required' => false,
                'complete' => true,
            ];
        }

        $required = ($currentConfig['effective_part_timestamps_enabled'] ?? false) === true;
        if (! $required) {
            return [
                'state' => 'not_required',
                'reason' => 'Current workflow config does not require multipart part timestamps.',
                'required' => false,
                'complete' => true,
            ];
        }

        if (($pushover['part_timestamps_enabled'] ?? null) !== true) {
            return [
                'state' => 'metadata_missing',
                'reason' => 'Current workflow config requires part timestamps, but the inspected run did not record them.',
                'required' => true,
                'complete' => false,
            ];
        }

        if (($pushover['part_timestamp_strategy'] ?? null) !== 'ascending_display_order') {
            return [
                'state' => 'strategy_inconsistent',
                'reason' => 'Part timestamp strategy is missing or not the expected ascending_display_order strategy.',
                'required' => true,
                'complete' => false,
            ];
        }

        $timestamps = $this->intMap($pushover['part_timestamps'] ?? null);
        $expectedParts = $totalParts > 0 ? range($totalParts, 1) : [];
        $actualParts = array_map(static fn ($key): int => (int) $key, array_keys($timestamps));
        if ($expectedParts !== $actualParts) {
            return [
                'state' => 'part_timestamps_incomplete',
                'reason' => 'Part timestamp metadata does not include every sent part in reverse send order.',
                'required' => true,
                'complete' => false,
            ];
        }

        $values = array_values($timestamps);
        $sortedValues = $values;
        sort($sortedValues);
        if ($values !== $sortedValues || count(array_unique($values)) !== count($values)) {
            return [
                'state' => 'part_timestamps_inconsistent',
                'reason' => 'Part timestamps are not strictly ascending in display order.',
                'required' => true,
                'complete' => false,
            ];
        }

        return [
            'state' => 'recorded',
            'reason' => 'Part timestamps were recorded for every multipart packet in ascending display order.',
            'required' => true,
            'complete' => true,
        ];
    }

    private function summarizePartHeaderProof(array $pushover, ?array $currentConfig): array
    {
        if (($pushover['present'] ?? false) !== true) {
            return [
                'state' => 'pushover_absent',
                'reason' => 'Pushover node was not found in the inspected run.',
                'required' => false,
                'complete' => false,
            ];
        }

        $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
        if ($totalParts === null || $totalParts <= 1) {
            return [
                'state' => 'not_required',
                'reason' => 'Single-part or unknown-part notification; part header proof is not required.',
                'required' => false,
                'complete' => true,
            ];
        }

        $required = ($currentConfig['effective_part_headers_enabled'] ?? false) === true;
        if (! $required) {
            return [
                'state' => 'not_required',
                'reason' => 'Current workflow config does not require multipart message headers.',
                'required' => false,
                'complete' => true,
            ];
        }

        if (($pushover['part_headers_enabled'] ?? null) !== true) {
            return [
                'state' => 'metadata_missing',
                'reason' => 'Current workflow config requires multipart message headers, but the inspected run did not record them.',
                'required' => true,
                'complete' => false,
            ];
        }

        if (($pushover['part_header_strategy'] ?? null) !== 'message_prefix') {
            return [
                'state' => 'strategy_inconsistent',
                'reason' => 'Multipart message header strategy is missing or not the expected message_prefix strategy.',
                'required' => true,
                'complete' => false,
            ];
        }

        return [
            'state' => 'recorded',
            'reason' => 'Multipart message headers were recorded as per-part message prefixes.',
            'required' => true,
            'complete' => true,
        ];
    }

    private function summarizePartContentProof(array $pushover): array
    {
        if (($pushover['present'] ?? false) !== true) {
            return [
                'state' => 'pushover_absent',
                'reason' => 'Pushover node was not found in the inspected run.',
                'complete' => false,
                'hashes_available' => false,
                'lengths_available' => false,
                'request_ids_available' => false,
                'distinct_hashes' => 0,
            ];
        }

        $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
        if ($totalParts === null || $totalParts <= 1) {
            return [
                'state' => 'not_required',
                'reason' => 'Single-part or unknown-part notification; per-part content proof is not required.',
                'complete' => true,
                'hashes_available' => false,
                'lengths_available' => false,
                'request_ids_available' => false,
                'distinct_hashes' => 0,
            ];
        }

        $sentParts = $this->intList($pushover['part_numbers_sent'] ?? null);
        $expectedParts = $totalParts > 0 ? range($totalParts, 1) : [];
        $hashes = $this->stringMap($pushover['part_message_hashes'] ?? null);
        $lengths = $this->intMap($pushover['part_message_lengths'] ?? null);
        $requestIds = $this->stringMap($pushover['part_response_requests'] ?? null);

        if ($hashes === [] || $lengths === []) {
            return [
                'state' => 'metadata_missing',
                'reason' => 'Per-part content fingerprints were not recorded for this run; use the next natural run for distinct-content proof.',
                'complete' => false,
                'hashes_available' => $hashes !== [],
                'lengths_available' => $lengths !== [],
                'request_ids_available' => $requestIds !== [],
                'distinct_hashes' => count(array_unique(array_values($hashes))),
            ];
        }

        $hashParts = array_map(static fn ($key): int => (int) $key, array_keys($hashes));
        $lengthParts = array_map(static fn ($key): int => (int) $key, array_keys($lengths));
        if ($hashParts !== $expectedParts || $lengthParts !== $expectedParts || ($sentParts !== [] && $sentParts !== $expectedParts)) {
            return [
                'state' => 'part_content_incomplete',
                'reason' => 'Per-part content fingerprints do not cover every sent multipart packet in reverse send order.',
                'complete' => false,
                'hashes_available' => true,
                'lengths_available' => true,
                'request_ids_available' => $requestIds !== [],
                'distinct_hashes' => count(array_unique(array_values($hashes))),
            ];
        }

        foreach ($hashes as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                return [
                    'state' => 'part_content_hash_invalid',
                    'reason' => 'Per-part content fingerprint metadata contains a malformed hash.',
                    'complete' => false,
                    'hashes_available' => true,
                    'lengths_available' => true,
                    'request_ids_available' => $requestIds !== [],
                    'distinct_hashes' => count(array_unique(array_values($hashes))),
                ];
            }
        }

        foreach ($lengths as $length) {
            if ($length <= 0) {
                return [
                    'state' => 'part_content_length_invalid',
                    'reason' => 'Per-part content fingerprint metadata contains an invalid message length.',
                    'complete' => false,
                    'hashes_available' => true,
                    'lengths_available' => true,
                    'request_ids_available' => $requestIds !== [],
                    'distinct_hashes' => count(array_unique(array_values($hashes))),
                ];
            }
        }

        $distinctHashes = count(array_unique(array_values($hashes)));
        if ($distinctHashes !== $totalParts) {
            return [
                'state' => 'duplicate_content_hash',
                'reason' => 'Per-part content fingerprints are present, but not every accepted multipart packet has distinct content.',
                'complete' => false,
                'hashes_available' => true,
                'lengths_available' => true,
                'request_ids_available' => $requestIds !== [],
                'distinct_hashes' => $distinctHashes,
            ];
        }

        return [
            'state' => 'recorded',
            'reason' => 'Per-part content fingerprints show every accepted multipart packet had distinct content without storing message text.',
            'complete' => true,
            'hashes_available' => true,
            'lengths_available' => true,
            'request_ids_available' => $requestIds !== [],
            'distinct_hashes' => $distinctHashes,
        ];
    }

    private function summarizeClientDisplayProof(
        array $pushover,
        array $partNumberProof,
        array $partTimestampProof,
        array $partHeaderProof,
        array $partContentProof
    ): array {
        if (($pushover['present'] ?? false) !== true) {
            return [
                'state' => 'pushover_absent',
                'reason' => 'Pushover node was not found in the inspected run.',
                'api_delivery_confirmed' => false,
                'device_display_verified' => false,
                'operator_device_check_required' => false,
            ];
        }

        $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
        if ($totalParts === null || $totalParts <= 1) {
            return [
                'state' => 'not_required',
                'reason' => 'Single-part notification; multipart client-display proof is not required.',
                'api_delivery_confirmed' => ($pushover['proof_state'] ?? null) === 'delivery_confirmed',
                'device_display_verified' => false,
                'operator_device_check_required' => false,
            ];
        }

        $timestampProofOk = ($partTimestampProof['required'] ?? false) !== true
            || ($partTimestampProof['state'] ?? null) === 'recorded';
        $headerProofOk = ($partHeaderProof['required'] ?? false) !== true
            || ($partHeaderProof['state'] ?? null) === 'recorded';
        $contentProofOk = in_array(($partContentProof['state'] ?? null), ['recorded', 'metadata_missing'], true);
        $apiDeliveryConfirmed = ($pushover['proof_state'] ?? null) === 'delivery_confirmed'
            && ($partNumberProof['state'] ?? null) === 'exact_reverse_sequence'
            && $timestampProofOk
            && $headerProofOk
            && $contentProofOk;

        if (! $apiDeliveryConfirmed) {
            return [
                'state' => 'api_delivery_not_confirmed',
                'reason' => 'Server-side Pushover API delivery proof is not complete enough to evaluate device display.',
                'api_delivery_confirmed' => false,
                'device_display_verified' => false,
                'operator_device_check_required' => false,
            ];
        }

        return [
            'state' => 'api_delivery_confirmed_device_unverified',
            'reason' => 'Server-side proof confirms Pushover accepted every multipart packet, but this command cannot prove what the mobile or desktop client displayed after delivery.',
            'api_delivery_confirmed' => true,
            'device_display_verified' => false,
            'operator_device_check_required' => true,
        ];
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

        $totalParts = $this->intOrNull($pushover['total_parts'] ?? null);
        if ($totalParts !== null && $totalParts > 1) {
            $partProof = $report['part_number_proof'] ?? [];
            if (! in_array(($partProof['state'] ?? null), ['exact_reverse_sequence'], true)) {
                $inconclusive[] = (string) ($partProof['reason'] ?? 'Multipart part-number proof is inconclusive.');
            }

            $pacing = $report['pacing_config'] ?? [];
            if (! in_array(($pacing['state'] ?? null), ['matches_current_config'], true)) {
                $inconclusive[] = (string) ($pacing['reason'] ?? 'Multipart Pushover pacing proof is inconclusive.');
            }

            $partTimestampProof = $report['part_timestamp_proof'] ?? [];
            if (($partTimestampProof['required'] ?? false) === true && ($partTimestampProof['state'] ?? null) !== 'recorded') {
                $inconclusive[] = (string) ($partTimestampProof['reason'] ?? 'Multipart part timestamp proof is inconclusive.');
            }

            $partHeaderProof = $report['part_header_proof'] ?? [];
            if (($partHeaderProof['required'] ?? false) === true && ($partHeaderProof['state'] ?? null) !== 'recorded') {
                $inconclusive[] = (string) ($partHeaderProof['reason'] ?? 'Multipart part header proof is inconclusive.');
            }

            $partContentProof = $report['part_content_proof'] ?? [];
            if (in_array(($partContentProof['state'] ?? null), [
                'part_content_incomplete',
                'part_content_hash_invalid',
                'part_content_length_invalid',
                'duplicate_content_hash',
            ], true)) {
                $inconclusive[] = (string) ($partContentProof['reason'] ?? 'Multipart part content proof is inconclusive.');
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
                'source_group' => $report['pushover']['source_group'] ?? null,
                'parts_sent' => $report['pushover']['parts_sent'] ?? null,
                'total_parts' => $report['pushover']['total_parts'] ?? null,
                'part_numbers_sent' => $report['pushover']['part_numbers_sent'] ?? [],
                'part_numbers_failed' => $report['pushover']['part_numbers_failed'] ?? [],
                'part_timestamps_enabled' => $report['pushover']['part_timestamps_enabled'] ?? null,
                'part_timestamp_strategy' => $report['pushover']['part_timestamp_strategy'] ?? null,
                'part_timestamps' => $report['pushover']['part_timestamps'] ?? [],
                'part_headers_enabled' => $report['pushover']['part_headers_enabled'] ?? null,
                'part_header_strategy' => $report['pushover']['part_header_strategy'] ?? null,
                'part_message_lengths' => $report['pushover']['part_message_lengths'] ?? [],
                'part_message_hashes' => $report['pushover']['part_message_hashes'] ?? [],
                'part_response_requests' => $report['pushover']['part_response_requests'] ?? [],
                'inter_chunk_delay_seconds' => $report['pushover']['inter_chunk_delay_seconds'] ?? null,
                'timed_out' => $report['pushover']['timed_out'] ?? null,
                'duration_ms' => $report['pushover']['duration_ms'] ?? null,
                'timeout_seconds' => $report['pushover']['timeout_seconds'] ?? null,
            ],
            'pacing_config' => $report['pacing_config'] ?? null,
            'current_pushover_config' => empty($report['current_pushover_config']) ? null : [
                'configured_inter_chunk_delay_seconds' => $report['current_pushover_config']['configured_inter_chunk_delay_seconds'] ?? null,
                'effective_inter_chunk_delay_seconds' => $report['current_pushover_config']['effective_inter_chunk_delay_seconds'] ?? null,
                'configured_part_timestamps_enabled' => $report['current_pushover_config']['configured_part_timestamps_enabled'] ?? null,
                'effective_part_timestamps_enabled' => $report['current_pushover_config']['effective_part_timestamps_enabled'] ?? null,
                'configured_part_headers_enabled' => $report['current_pushover_config']['configured_part_headers_enabled'] ?? null,
                'effective_part_headers_enabled' => $report['current_pushover_config']['effective_part_headers_enabled'] ?? null,
            ],
            'part_number_proof' => $report['part_number_proof'] ?? null,
            'part_timestamp_proof' => $report['part_timestamp_proof'] ?? null,
            'part_header_proof' => $report['part_header_proof'] ?? null,
            'part_content_proof' => $report['part_content_proof'] ?? null,
            'client_display_proof' => $report['client_display_proof'] ?? null,
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
            .' source_group='.($pushover['source_group'] ?? 'n/a')
            .' parts='.($pushover['parts_sent'] ?? 'n/a').'/'.($pushover['total_parts'] ?? 'n/a')
            .' failed_parts='.implode(',', $pushover['part_numbers_failed'] ?? [])
            .' delay_s='.($pushover['inter_chunk_delay_seconds'] ?? 'n/a')
            .' part_timestamps='.var_export($pushover['part_timestamps_enabled'] ?? null, true)
            .' timeout='.var_export($pushover['timed_out'], true)
            .' duration_ms='.($pushover['duration_ms'] ?? 'n/a'));

        if (! empty($report['pacing_config'])) {
            $pacing = $report['pacing_config'];
            $this->line('Pacing config: state='.$pacing['state']
                .' run_delay_s='.($pacing['run_inter_chunk_delay_seconds'] ?? 'n/a')
                .' current_delay_s='.($pacing['current_effective_inter_chunk_delay_seconds'] ?? 'n/a')
                .' matches='.var_export($pacing['matches_current_config'] ?? null, true)
                .' reason='.$pacing['reason']);
        }

        if (! empty($report['part_number_proof'])) {
            $partProof = $report['part_number_proof'];
            $this->line('Part numbers: state='.$partProof['state']
                .' complete='.var_export($partProof['complete'] ?? null, true)
                .' exact='.var_export($partProof['exact_part_numbers_available'] ?? null, true)
                .' actual='.implode(',', $partProof['actual_part_numbers_sent'] ?? [])
                .' reason='.$partProof['reason']);
        }

        if (! empty($report['part_timestamp_proof'])) {
            $timestampProof = $report['part_timestamp_proof'];
            $this->line('Part timestamps: state='.$timestampProof['state']
                .' required='.var_export($timestampProof['required'] ?? null, true)
                .' complete='.var_export($timestampProof['complete'] ?? null, true)
                .' reason='.$timestampProof['reason']);
        }

        if (! empty($report['part_header_proof'])) {
            $headerProof = $report['part_header_proof'];
            $this->line('Part headers: state='.$headerProof['state']
                .' required='.var_export($headerProof['required'] ?? null, true)
                .' complete='.var_export($headerProof['complete'] ?? null, true)
                .' reason='.$headerProof['reason']);
        }

        if (! empty($report['part_content_proof'])) {
            $contentProof = $report['part_content_proof'];
            $this->line('Part content: state='.$contentProof['state']
                .' complete='.var_export($contentProof['complete'] ?? null, true)
                .' hashes='.var_export($contentProof['hashes_available'] ?? null, true)
                .' lengths='.var_export($contentProof['lengths_available'] ?? null, true)
                .' requests='.var_export($contentProof['request_ids_available'] ?? null, true)
                .' distinct_hashes='.($contentProof['distinct_hashes'] ?? 'n/a')
                .' reason='.$contentProof['reason']);
        }

        if (! empty($report['client_display_proof'])) {
            $displayProof = $report['client_display_proof'];
            $this->line('Client display: state='.$displayProof['state']
                .' api_delivery='.var_export($displayProof['api_delivery_confirmed'] ?? null, true)
                .' device_display='.var_export($displayProof['device_display_verified'] ?? null, true)
                .' operator_check='.var_export($displayProof['operator_device_check_required'] ?? null, true)
                .' reason='.$displayProof['reason']);
        }

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
            .' source_group='.($pushover['source_group'] ?? 'n/a')
            .' parts='.($pushover['parts_sent'] ?? 'n/a').'/'.($pushover['total_parts'] ?? 'n/a')
            .' failed_parts='.implode(',', $pushover['part_numbers_failed'] ?? [])
            .' delay_s='.($pushover['inter_chunk_delay_seconds'] ?? 'n/a')
            .' part_timestamps='.var_export($pushover['part_timestamps_enabled'] ?? null, true)
            .' timeout='.var_export($pushover['timed_out'] ?? null, true)
            .' duration_ms='.($pushover['duration_ms'] ?? 'n/a'));

        if (! empty($report['pacing_config'])) {
            $pacing = $report['pacing_config'];
            $this->line('Pacing config: state='.$pacing['state']
                .' run_delay_s='.($pacing['run_inter_chunk_delay_seconds'] ?? 'n/a')
                .' current_delay_s='.($pacing['current_effective_inter_chunk_delay_seconds'] ?? 'n/a')
                .' matches='.var_export($pacing['matches_current_config'] ?? null, true)
                .' reason='.$pacing['reason']);
        }

        if (! empty($report['part_number_proof'])) {
            $partProof = $report['part_number_proof'];
            $this->line('Part numbers: state='.$partProof['state']
                .' complete='.var_export($partProof['complete'] ?? null, true)
                .' exact='.var_export($partProof['exact_part_numbers_available'] ?? null, true)
                .' actual='.implode(',', $partProof['actual_part_numbers_sent'] ?? [])
                .' reason='.$partProof['reason']);
        }

        if (! empty($report['part_timestamp_proof'])) {
            $timestampProof = $report['part_timestamp_proof'];
            $this->line('Part timestamps: state='.$timestampProof['state']
                .' required='.var_export($timestampProof['required'] ?? null, true)
                .' complete='.var_export($timestampProof['complete'] ?? null, true)
                .' reason='.$timestampProof['reason']);
        }

        if (! empty($report['part_header_proof'])) {
            $headerProof = $report['part_header_proof'];
            $this->line('Part headers: state='.$headerProof['state']
                .' required='.var_export($headerProof['required'] ?? null, true)
                .' complete='.var_export($headerProof['complete'] ?? null, true)
                .' reason='.$headerProof['reason']);
        }

        if (! empty($report['part_content_proof'])) {
            $contentProof = $report['part_content_proof'];
            $this->line('Part content: state='.$contentProof['state']
                .' complete='.var_export($contentProof['complete'] ?? null, true)
                .' hashes='.var_export($contentProof['hashes_available'] ?? null, true)
                .' lengths='.var_export($contentProof['lengths_available'] ?? null, true)
                .' requests='.var_export($contentProof['request_ids_available'] ?? null, true)
                .' distinct_hashes='.($contentProof['distinct_hashes'] ?? 'n/a')
                .' reason='.$contentProof['reason']);
        }

        if (! empty($report['client_display_proof'])) {
            $displayProof = $report['client_display_proof'];
            $this->line('Client display: state='.$displayProof['state']
                .' api_delivery='.var_export($displayProof['api_delivery_confirmed'] ?? null, true)
                .' device_display='.var_export($displayProof['device_display_verified'] ?? null, true)
                .' operator_check='.var_export($displayProof['operator_device_check_required'] ?? null, true)
                .' reason='.$displayProof['reason']);
        }

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

    /**
     * @return array<string, int>
     */
    private function intMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $mapped = [];
        foreach ($value as $key => $item) {
            if (! is_numeric($item)) {
                continue;
            }

            $mapped[(string) $key] = (int) $item;
        }

        return $mapped;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $mapped = [];
        foreach ($value as $key => $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $string = trim((string) $item);
            if ($string === '') {
                continue;
            }

            $mapped[(string) $key] = $string;
        }

        return $mapped;
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
