<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

class GenAiTelemetryExportService
{
    private const MAX_LIMIT = 5000;

    private const WORKFLOW_NODE_LIMIT_MULTIPLIER = 20;

    public function __construct(private readonly OtlpTraceJsonValidatorService $otlpValidator) {}

    /**
     * Export existing PLOS telemetry without prompt, completion, tool payload, or raw session content.
     *
     * @return array<string, mixed>
     */
    public function export(int $hours = 24, int $limit = 500, string $source = 'all'): array
    {
        $hours = max(1, min($hours, 24 * 31));
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $source = strtolower(trim($source));

        if (! in_array($source, ['all', 'models', 'agents', 'retrievals', 'workflows'], true)) {
            throw new InvalidArgumentException('Source must be all, models, agents, retrievals, or workflows.');
        }

        $since = CarbonImmutable::now()->subHours($hours)->format('Y-m-d H:i:s');
        $warnings = [];
        $modelRows = [];
        $agentRows = [];
        $retrievalRows = [];
        $workflowRows = [];
        $workflowNodeRows = [];

        if (in_array($source, ['all', 'models'], true)) {
            try {
                if (Schema::hasTable('llm_model_request_logs')) {
                    $routeColumns = Schema::hasColumns(
                        'llm_model_request_logs',
                        ['route_trace_id', 'route_attempt_index']
                    ) ? ', route_trace_id, route_attempt_index' : '';
                    $usageColumns = Schema::hasColumns(
                        'llm_model_request_logs',
                        ['input_tokens', 'cached_input_tokens', 'cache_write_input_tokens', 'output_tokens', 'reasoning_output_tokens']
                    ) ? ', input_tokens, cached_input_tokens, cache_write_input_tokens, output_tokens, reasoning_output_tokens' : '';
                    $modelRows = DB::select(
                        'SELECT id, instance_id, instance_type, model_name, caller, model_role,
                                request_kind, success, latency_ms, tokens_per_second, quality_score,
                                escalated'.$routeColumns.$usageColumns.', created_at
                         FROM llm_model_request_logs
                         WHERE created_at >= ?
                         ORDER BY created_at DESC
                         LIMIT ?',
                        [$since, $limit]
                    );
                } else {
                    $warnings[] = 'llm_model_request_logs_unavailable';
                }
            } catch (Throwable) {
                $warnings[] = 'llm_model_request_logs_query_failed';
            }
        }

        if (in_array($source, ['all', 'agents'], true)) {
            try {
                if (Schema::hasTable('agent_execution_log')) {
                    $agentRows = DB::select(
                        'SELECT id, session_id, agent_name, action_type, action_detail, risk_level,
                                role, duration_ms, success, outcome,
                                CASE WHEN JSON_VALID(context)
                                     THEN JSON_UNQUOTE(JSON_EXTRACT(context, \'$.tool_call_id\'))
                                     ELSE NULL END AS tool_call_id,
                                created_at
                         FROM agent_execution_log
                         WHERE created_at >= ?
                         ORDER BY created_at DESC
                         LIMIT ?',
                        [$since, $limit]
                    );
                } else {
                    $warnings[] = 'agent_execution_log_unavailable';
                }
            } catch (Throwable) {
                $warnings[] = 'agent_execution_log_query_failed';
            }
        }

        if (in_array($source, ['all', 'retrievals'], true)) {
            try {
                if (Schema::connection('pgsql_rag')->hasTable('rag_query_traces')) {
                    $retrievalRows = DB::connection('pgsql_rag')->select(
                        "SELECT id, strategy_used, retrieval_time_ms, rerank_time_ms, total_time_ms,
                                result_count, top_similarity, hyde_used, raptor_used,
                                COALESCE(filters_applied, '{}'::jsonb) <> '{}'::jsonb AS filters_used,
                                created_at
                         FROM rag_query_traces
                         WHERE created_at >= ?
                         ORDER BY created_at DESC
                         LIMIT ?",
                        [$since, $limit]
                    );
                } else {
                    $warnings[] = 'rag_query_traces_unavailable';
                }
            } catch (Throwable) {
                $warnings[] = 'rag_query_traces_query_failed';
            }
        }

        if (in_array($source, ['all', 'workflows'], true)) {
            try {
                if (Schema::hasTable('workflow_runs')) {
                    $workflowRows = DB::select(
                        "SELECT id, workflow_id, status, started_at, completed_at,
                                parent_run_id, parent_node_execution_id, depth,
                                total_duration_ms, nodes_executed
                         FROM workflow_runs
                         WHERE completed_at >= ?
                           AND completed_at IS NOT NULL
                           AND status IN ('completed', 'failed')
                         ORDER BY completed_at DESC
                         LIMIT ?",
                        [$since, $limit]
                    );
                } else {
                    $warnings[] = 'workflow_runs_unavailable';
                }
            } catch (Throwable) {
                $warnings[] = 'workflow_runs_query_failed';
            }

            if ($workflowRows !== []) {
                try {
                    if (Schema::hasTable('node_executions')) {
                        $runIds = array_values(array_unique(array_map(
                            static fn (object|array $row): int => (int) ((array) $row)['id'],
                            $workflowRows
                        )));
                        $placeholders = implode(',', array_fill(0, count($runIds), '?'));
                        $nodeLimit = min(self::MAX_LIMIT, $limit * self::WORKFLOW_NODE_LIMIT_MULTIPLIER);
                        $workflowNodeRows = DB::select(
                            "SELECT id, run_id, node_type, node_order, duration_ms,
                                    executed_at, branch_index, state, timed_out
                             FROM node_executions
                             WHERE run_id IN ({$placeholders})
                             ORDER BY executed_at DESC
                             LIMIT ?",
                            [...$runIds, $nodeLimit]
                        );
                    } else {
                        $warnings[] = 'node_executions_unavailable';
                    }
                } catch (Throwable) {
                    $warnings[] = 'node_executions_query_failed';
                }
            }
        }

        return $this->buildPayload($modelRows, $agentRows, [
            'hours' => $hours,
            'limit_per_source' => $limit,
            'source' => $source,
            'warnings' => $warnings,
        ], $retrievalRows, $workflowRows, $workflowNodeRows);
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $modelRows
     * @param  array<int, object|array<string, mixed>>  $agentRows
     * @param  array<string, mixed>  $query
     * @param  array<int, object|array<string, mixed>>  $retrievalRows
     * @param  array<int, object|array<string, mixed>>  $workflowRows
     * @param  array<int, object|array<string, mixed>>  $workflowNodeRows
     * @return array<string, mixed>
     */
    public function buildPayload(
        array $modelRows,
        array $agentRows,
        array $query = [],
        array $retrievalRows = [],
        array $workflowRows = [],
        array $workflowNodeRows = []
    ): array {
        $spans = [];

        foreach ($modelRows as $row) {
            $spans[] = $this->modelSpan((array) $row);
        }

        foreach ($agentRows as $row) {
            $spans[] = $this->agentSpan((array) $row);
        }

        foreach ($retrievalRows as $row) {
            $spans[] = $this->retrievalSpan((array) $row);
        }

        $workflowRunMap = [];
        foreach ($workflowRows as $row) {
            $workflowRun = (array) $row;
            $status = strtolower(trim((string) ($workflowRun['status'] ?? '')));
            if (! in_array($status, ['completed', 'failed'], true)
                || trim((string) ($workflowRun['completed_at'] ?? '')) === '') {
                continue;
            }

            $workflowRunMap[(int) ($workflowRun['id'] ?? 0)] = $workflowRun;
        }

        $workflowNodeMap = [];
        $workflowNodesByRun = [];
        foreach ($workflowNodeRows as $row) {
            $workflowNode = (array) $row;
            $workflowNodeMap[(int) ($workflowNode['id'] ?? 0)] = $workflowNode;
            $workflowNodesByRun[(int) ($workflowNode['run_id'] ?? 0)][] = $workflowNode;
        }

        foreach ($workflowRunMap as $runId => $row) {
            $rootRunId = $this->workflowRootRunId($runId, $workflowRunMap);
            $parentSpanId = $this->workflowParentSpanId($row, $workflowRunMap, $workflowNodeMap);
            $spans[] = $this->workflowSpan(
                $row,
                $rootRunId,
                $parentSpanId,
                $workflowNodesByRun[$runId] ?? []
            );
        }

        foreach ($workflowNodeMap as $row) {
            $runId = (int) ($row['run_id'] ?? 0);
            if (! isset($workflowRunMap[$runId])) {
                continue;
            }

            $rootRunId = $this->workflowRootRunId($runId, $workflowRunMap);
            $spans[] = $this->workflowNodeSpan($row, $rootRunId);
        }

        usort(
            $spans,
            static fn (array $left, array $right): int => ((int) $right['startTimeUnixNano']) <=> ((int) $left['startTimeUnixNano'])
        );

        $otlp = [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => $this->attributes([
                        'service.name' => 'plos',
                        'service.namespace' => 'personal-life-os',
                        'deployment.environment.name' => (string) app()->environment(),
                        'plos.telemetry.content_capture' => false,
                    ]),
                ],
                'scopeSpans' => [[
                    'scope' => [
                        'name' => 'plos.gen_ai.telemetry',
                        'version' => '1.6.0',
                    ],
                    'spans' => $spans,
                ]],
            ]],
        ];

        $otlpValidation = $this->otlpValidator->validate($otlp);

        return [
            'schema' => 'plos_gen_ai_telemetry_export.v1',
            'status' => empty($query['warnings']) ? 'pass' : 'partial',
            'generated_at' => now()->utc()->toIso8601String(),
            'conventions' => [
                'family' => 'OpenTelemetry GenAI Semantic Conventions',
                'stability' => 'development',
                'reviewed_at' => '2026-07-14',
                'revision' => '93a59e48a9b4ea162a4d76edac4ace2d415a759e',
                'upstream' => 'https://github.com/open-telemetry/semantic-conventions-genai',
            ],
            'interoperability' => [
                'openinference' => [
                    'profile' => 'content-free',
                    'required_span_kind' => true,
                    'reviewed_at' => '2026-07-14',
                    'revision' => '4254eeaa702cda4518e09b3e44a288091176cd6c',
                    'upstream' => 'https://github.com/Arize-ai/openinference',
                ],
                'phoenix' => [
                    'compatible_transport' => 'OTLP/JSON',
                    'collector_bundled' => false,
                    'automatic_export' => false,
                ],
                'otlp_json' => $otlpValidation,
            ],
            'tool_call_identity' => [
                'attribute' => 'gen_ai.tool.call.id',
                'source' => 'mcp_tool_calls.id mirrored to agent_execution_log.context',
                'format' => 'plos_call_<positive_database_id>',
                'historical_identity_inferred' => false,
                'arguments_or_results_included' => false,
                'raw_context_included' => false,
            ],
            'privacy' => [
                'content_capture' => false,
                'raw_session_ids' => false,
                'omitted_fields' => [
                    'prompts',
                    'completions',
                    'input_summary',
                    'output_summary',
                    'tool_arguments',
                    'tool_results',
                    'context',
                    'error_summary',
                    'filesystem_paths',
                    'input.value',
                    'output.value',
                    'llm.input_messages',
                    'llm.output_messages',
                    'llm.prompts',
                    'llm.choices',
                    'embedding.text',
                    'embedding.vector',
                    'embedding.embeddings',
                    'document.content',
                    'document.metadata',
                    'gen_ai.retrieval.query.text',
                    'gen_ai.retrieval.documents',
                    'retrieval.documents',
                    'query_text',
                    'filters_applied',
                    'metadata',
                    'tool.parameters',
                    'tool.json_schema',
                    'tool_call.function.arguments',
                    'tool_call.reasoning_signature',
                    'message.content',
                    'message_content.text',
                    'message_content.data',
                    'message_content.signature',
                    'message_content.encrypted_content',
                    'exception.message',
                    'exception.stacktrace',
                    'image.url',
                    'audio.url',
                    'audio.transcript',
                    'workflow.name',
                    'workflow.input',
                    'workflow.output',
                    'workflow.error_message',
                    'node.input',
                    'node.output',
                    'node.error_message',
                ],
            ],
            'query' => $query,
            'summary' => [
                'spans' => count($spans),
                'model_spans' => count($modelRows),
                'agent_spans' => count($agentRows),
                'retrieval_spans' => count($retrievalRows),
                'workflow_spans' => count($workflowRunMap),
                'workflow_node_spans' => count(array_filter(
                    $workflowNodeMap,
                    static fn (array $row): bool => isset($workflowRunMap[(int) ($row['run_id'] ?? 0)])
                )),
                'tool_spans' => count(array_filter(
                    $spans,
                    static fn (array $span): bool => str_starts_with((string) $span['name'], 'execute_tool ')
                )),
                'tool_spans_with_call_id' => count(array_filter(
                    $spans,
                    fn (array $span): bool => $this->spanHasAttribute($span, 'gen_ai.tool.call.id')
                )),
                'error_spans' => count(array_filter(
                    $spans,
                    static fn (array $span): bool => (int) ($span['status']['code'] ?? 0) === 2
                )),
                'openinference_spans' => count(array_filter(
                    $spans,
                    fn (array $span): bool => $this->spanHasAttribute($span, 'openinference.span.kind')
                )),
            ],
            'otlp' => $otlp,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function modelSpan(array $row): array
    {
        $id = (string) ($row['id'] ?? '0');
        $operation = $this->modelOperation((string) ($row['request_kind'] ?? 'chat'));
        $model = $this->lowCardinality((string) ($row['model_name'] ?? ''), 'unknown-model');
        $success = (bool) ($row['success'] ?? false);
        $durationMs = max(0, (float) ($row['latency_ms'] ?? 0));
        [$start, $end] = $this->timeRange($row['created_at'] ?? null, $durationMs);

        $attributes = [
            'gen_ai.operation.name' => $operation,
            'gen_ai.provider.name' => $this->providerName((string) ($row['instance_type'] ?? '')),
            'gen_ai.request.model' => $model,
            'gen_ai.response.model' => $model,
            'openinference.span.kind' => $operation === 'embeddings' ? 'EMBEDDING' : 'LLM',
            'plos.telemetry.source' => 'llm_model_request_logs',
            'plos.model.instance_id' => $this->lowCardinality((string) ($row['instance_id'] ?? ''), 'unknown-instance'),
            'plos.request.kind' => $this->lowCardinality((string) ($row['request_kind'] ?? ''), 'unknown'),
            'plos.request.caller' => $this->lowCardinality((string) ($row['caller'] ?? ''), 'unknown'),
            'plos.model.role' => $this->lowCardinality((string) ($row['model_role'] ?? ''), 'unknown'),
            'plos.success' => $success,
        ];

        if ($operation === 'embeddings') {
            $attributes['embedding.model_name'] = $model;
        } else {
            $attributes['llm.model_name'] = $model;
            $attributes['llm.system'] = $this->openInferenceSystem((string) ($row['instance_type'] ?? ''));
            $attributes['llm.provider'] = $this->openInferenceProvider((string) ($row['instance_type'] ?? ''));
        }

        $this->addNumeric($attributes, 'plos.latency_ms', $row['latency_ms'] ?? null);
        $this->addNumeric($attributes, 'plos.tokens_per_second', $row['tokens_per_second'] ?? null);
        $this->addNumeric($attributes, 'gen_ai.usage.input_tokens', $row['input_tokens'] ?? null);
        $this->addNumeric($attributes, 'gen_ai.usage.output_tokens', $row['output_tokens'] ?? null);
        $this->addNumeric($attributes, 'plos.gen_ai.usage.cached_input_tokens', $row['cached_input_tokens'] ?? null);
        $this->addNumeric($attributes, 'plos.gen_ai.usage.cache_write_input_tokens', $row['cache_write_input_tokens'] ?? null);
        $this->addNumeric($attributes, 'plos.gen_ai.usage.reasoning_output_tokens', $row['reasoning_output_tokens'] ?? null);
        $this->addNumeric($attributes, 'plos.quality.score', $row['quality_score'] ?? null);
        if (array_key_exists('escalated', $row) && $row['escalated'] !== null) {
            $attributes['plos.request.escalated'] = (bool) $row['escalated'];
        }
        $routeAttemptIndex = filter_var(
            $row['route_attempt_index'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        if ($routeAttemptIndex !== false) {
            $attributes['plos.route.attempt_index'] = $routeAttemptIndex;
        }
        if (! $success) {
            $attributes['error.type'] = 'model_request_error';
        }

        $routeTraceId = strtolower(trim((string) ($row['route_trace_id'] ?? '')));
        if (preg_match('/^[0-9a-f]{32}$/', $routeTraceId) !== 1
            || $routeTraceId === str_repeat('0', 32)) {
            $routeTraceId = $this->hexId('model-trace:'.$id, 32);
        }

        return $this->span(
            traceId: $routeTraceId,
            spanId: $this->hexId('model-span:'.$id, 16),
            name: $operation.' '.$model,
            kind: 3,
            start: $start,
            end: $end,
            attributes: $attributes,
            success: $success,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function agentSpan(array $row): array
    {
        $id = (string) ($row['id'] ?? '0');
        $sessionId = (string) ($row['session_id'] ?? '');
        $correlationSeed = $sessionId !== '' ? $sessionId : 'row:'.$id;
        $agent = $this->lowCardinality((string) ($row['agent_name'] ?? ''), 'unknown-agent');
        $action = $this->lowCardinality((string) ($row['action_type'] ?? ''), 'unknown');
        $isTool = $action === 'tool_call';
        $operation = $isTool ? 'execute_tool' : 'invoke_agent';
        $target = $isTool
            ? $this->lowCardinality((string) ($row['action_detail'] ?? ''), 'unknown-tool')
            : $agent;
        $success = (bool) ($row['success'] ?? false) && (string) ($row['outcome'] ?? '') === 'success';
        $durationMs = max(0, (float) ($row['duration_ms'] ?? 0));
        [$start, $end] = $this->timeRange($row['created_at'] ?? null, $durationMs);
        $correlationId = $this->correlationId($correlationSeed);

        $attributes = [
            'gen_ai.operation.name' => $operation,
            'gen_ai.provider.name' => 'plos',
            'openinference.span.kind' => $this->openInferenceAgentSpanKind($action),
            'plos.telemetry.source' => 'agent_execution_log',
            'plos.agent.action_type' => $action,
            'plos.agent.risk_level' => $this->lowCardinality((string) ($row['risk_level'] ?? ''), 'unknown'),
            'plos.agent.outcome' => $this->lowCardinality((string) ($row['outcome'] ?? ''), 'unknown'),
            'plos.agent.role' => $this->lowCardinality((string) ($row['role'] ?? ''), 'unknown'),
            'plos.correlation.id' => $correlationId,
            'session.id' => $correlationId,
            'plos.success' => $success,
        ];

        if ($isTool) {
            $attributes['gen_ai.tool.name'] = $target;
            $attributes['gen_ai.tool.type'] = 'extension';
            $toolCallId = trim((string) ($row['tool_call_id'] ?? ''));
            if (preg_match('/\Aplos_call_[1-9][0-9]{0,19}\z/D', $toolCallId) === 1) {
                $attributes['gen_ai.tool.call.id'] = $toolCallId;
            }
            if (trim((string) ($row['agent_name'] ?? '')) !== '') {
                $attributes['gen_ai.agent.name'] = $agent;
            }
            $attributes['tool.name'] = $target;
        } else {
            $attributes['gen_ai.agent.name'] = $agent;
            if ($attributes['openinference.span.kind'] === 'AGENT') {
                $attributes['agent.name'] = $agent;
            }
        }
        $this->addNumeric($attributes, 'plos.duration_ms', $row['duration_ms'] ?? null);
        if (! $success) {
            $attributes['error.type'] = 'agent_'.$this->lowCardinality((string) ($row['outcome'] ?? ''), 'failure');
        }

        return $this->span(
            traceId: $this->hexId('agent-trace:'.$correlationId, 32),
            spanId: $this->hexId('agent-span:'.$id, 16),
            name: $operation.' '.$target,
            kind: 1,
            start: $start,
            end: $end,
            attributes: $attributes,
            success: $success,
        );
    }

    /**
     * Map authoritative RAG trace metrics without reading query or result content.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function retrievalSpan(array $row): array
    {
        $id = (string) ($row['id'] ?? '0');
        $strategy = $this->lowCardinality((string) ($row['strategy_used'] ?? ''), 'unknown');
        $success = array_key_exists('total_time_ms', $row)
            && $row['total_time_ms'] !== null
            && ! str_starts_with($strategy, 'error_');
        $durationMs = max(0, (float) ($row['total_time_ms'] ?? 0));
        [$start, $end] = $this->timeRange($row['created_at'] ?? null, $durationMs);

        $attributes = [
            'gen_ai.operation.name' => 'retrieval',
            'gen_ai.provider.name' => 'plos',
            'gen_ai.data_source.id' => 'plos-rag',
            'openinference.span.kind' => 'RETRIEVER',
            'plos.telemetry.source' => 'rag_query_traces',
            'plos.retrieval.strategy' => $strategy,
            'plos.retrieval.hyde_used' => $this->booleanValue($row['hyde_used'] ?? false),
            'plos.retrieval.raptor_used' => $this->booleanValue($row['raptor_used'] ?? false),
            'plos.retrieval.rerank_used' => is_numeric($row['rerank_time_ms'] ?? null)
                && (float) $row['rerank_time_ms'] > 0,
            'plos.retrieval.filters_used' => $this->booleanValue($row['filters_used'] ?? false),
            'plos.success' => $success,
        ];

        $this->addNumeric($attributes, 'plos.retrieval.retrieval_time_ms', $row['retrieval_time_ms'] ?? null);
        $this->addNumeric($attributes, 'plos.retrieval.rerank_time_ms', $row['rerank_time_ms'] ?? null);
        $this->addNumeric($attributes, 'plos.retrieval.total_time_ms', $row['total_time_ms'] ?? null);
        $this->addNumeric($attributes, 'plos.retrieval.result_count', $row['result_count'] ?? null);
        $this->addNumeric($attributes, 'plos.retrieval.top_similarity', $row['top_similarity'] ?? null);

        if (! $success) {
            $attributes['error.type'] = str_starts_with($strategy, 'error_')
                ? 'retrieval_'.$strategy
                : 'retrieval_incomplete';
        }

        return $this->span(
            traceId: $this->hexId('retrieval-trace:'.$id, 32),
            spanId: $this->hexId('retrieval-span:'.$id, 16),
            name: 'retrieval plos-rag',
            kind: 3,
            start: $start,
            end: $end,
            attributes: $attributes,
            success: $success,
        );
    }

    /**
     * Emit a terminal workflow execution span. Queued placeholders and running
     * records never reach this mapper.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function workflowSpan(
        array $row,
        int $rootRunId,
        ?string $parentSpanId,
        array $nodeRows = []
    ): array {
        $runId = (int) ($row['id'] ?? 0);
        $status = $this->lowCardinality((string) ($row['status'] ?? ''), 'unknown');
        $success = $status === 'completed';
        [$start, $end, $durationMs] = $this->completedTimeRange(
            $row['started_at'] ?? null,
            $row['completed_at'] ?? null,
            $nodeRows
        );

        $attributes = [
            'openinference.span.kind' => 'CHAIN',
            'plos.telemetry.source' => 'workflow_runs',
            'plos.workflow.status' => $status,
            'plos.workflow.actual_execution' => true,
            'plos.workflow.terminal_record' => true,
            'plos.workflow.has_parent' => (int) ($row['parent_run_id'] ?? 0) > 0,
            'plos.workflow.correlation.id' => $this->correlationId('workflow-run:'.$runId),
            'plos.workflow.definition.id' => $this->correlationId('workflow:'.(int) ($row['workflow_id'] ?? 0)),
            'plos.success' => $success,
        ];
        $this->addNumeric($attributes, 'plos.workflow.depth', $row['depth'] ?? null);
        $this->addNumeric($attributes, 'plos.workflow.nodes_executed', $row['nodes_executed'] ?? null);
        $this->addNumeric($attributes, 'plos.workflow.duration_ms', $durationMs);
        if (! $success) {
            $attributes['error.type'] = 'workflow_failed';
        }

        return $this->span(
            traceId: $this->hexId('workflow-trace:'.$rootRunId, 32),
            spanId: $this->workflowRunSpanId($runId),
            name: 'execute_workflow plos-workflow',
            kind: 1,
            start: $start,
            end: $end,
            attributes: $attributes,
            success: $success,
            parentSpanId: $parentSpanId,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function workflowNodeSpan(array $row, int $rootRunId): array
    {
        $nodeId = (int) ($row['id'] ?? 0);
        $runId = (int) ($row['run_id'] ?? 0);
        $state = $this->lowCardinality((string) ($row['state'] ?? ''), 'unknown');
        $timedOut = $this->booleanValue($row['timed_out'] ?? false);
        $success = in_array($state, ['success', 'skipped'], true) && ! $timedOut;
        $durationMs = max(0, (float) ($row['duration_ms'] ?? 0));
        [$start, $end] = $this->startTimeRange($row['executed_at'] ?? null, $durationMs);
        $nodeType = $this->lowCardinality((string) ($row['node_type'] ?? ''), 'unknown-node');

        $attributes = [
            'openinference.span.kind' => 'CHAIN',
            'plos.telemetry.source' => 'node_executions',
            'plos.workflow.node.type' => $nodeType,
            'plos.workflow.node.state' => $state,
            'plos.workflow.node.timed_out' => $timedOut,
            'plos.workflow.correlation.id' => $this->correlationId('workflow-run:'.$runId),
            'plos.success' => $success,
        ];
        $this->addNumeric($attributes, 'plos.workflow.node.order', $row['node_order'] ?? null);
        $this->addNumeric($attributes, 'plos.workflow.node.branch_index', $row['branch_index'] ?? null);
        $this->addNumeric($attributes, 'plos.workflow.node.duration_ms', $durationMs);
        if (! $success) {
            $attributes['error.type'] = $timedOut ? 'workflow_node_timeout' : 'workflow_node_'.$state;
        }

        return $this->span(
            traceId: $this->hexId('workflow-trace:'.$rootRunId, 32),
            spanId: $this->workflowNodeSpanId($nodeId),
            name: 'workflow_node '.$nodeType,
            kind: 1,
            start: $start,
            end: $end,
            attributes: $attributes,
            success: $success,
            parentSpanId: $this->workflowRunSpanId($runId),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function span(
        string $traceId,
        string $spanId,
        string $name,
        int $kind,
        string $start,
        string $end,
        array $attributes,
        bool $success,
        ?string $parentSpanId = null,
    ): array {
        $span = [
            'traceId' => $this->otlpBytesId($traceId, 16),
            'spanId' => $this->otlpBytesId($spanId, 8),
            'name' => $name,
            'kind' => $kind,
            'startTimeUnixNano' => $start,
            'endTimeUnixNano' => $end,
            'attributes' => $this->attributes($attributes),
            'status' => ['code' => $success ? 1 : 2],
        ];

        if ($parentSpanId !== null && $parentSpanId !== '') {
            $span['parentSpanId'] = $this->otlpBytesId($parentSpanId, 8);
        }

        return $span;
    }

    private function otlpBytesId(string $hex, int $bytes): string
    {
        $hex = strtolower(trim($hex));
        if (preg_match('/\A[0-9a-f]{'.($bytes * 2).'}\z/D', $hex) !== 1
            || $hex === str_repeat('0', $bytes * 2)) {
            throw new UnexpectedValueException("OTLP identity must contain {$bytes} nonzero bytes.");
        }

        $binary = hex2bin($hex);
        if ($binary === false) {
            throw new UnexpectedValueException('Unable to encode OTLP identity bytes.');
        }

        return base64_encode($binary);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<int, array{key:string,value:array<string, mixed>}>
     */
    private function attributes(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $encoded = match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => (string) $value],
            };

            $result[] = ['key' => (string) $key, 'value' => $encoded];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addNumeric(array &$attributes, string $key, mixed $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $number = (float) $value;
        $attributes[$key] = floor($number) === $number ? (int) $number : $number;
    }

    private function modelOperation(string $requestKind): string
    {
        return match (strtolower(trim($requestKind))) {
            'embedding', 'embeddings' => 'embeddings',
            'image', 'audio', 'video', 'multimodal', 'generation' => 'generate_content',
            'completion', 'text_completion' => 'text_completion',
            'retrieval', 'search' => 'retrieval',
            default => 'chat',
        };
    }

    private function providerName(string $instanceType): string
    {
        $value = strtolower(trim($instanceType));

        return match (true) {
            str_contains($value, 'anthropic') => 'anthropic',
            str_contains($value, 'openai') => 'openai',
            str_contains($value, 'gemini'), str_contains($value, 'google') => 'gcp.gemini',
            str_contains($value, 'mistral') => 'mistral_ai',
            str_contains($value, 'cohere') => 'cohere',
            str_contains($value, 'groq') => 'groq',
            str_contains($value, 'ollama') => 'ollama',
            default => $this->lowCardinality($value, 'unknown'),
        };
    }

    private function openInferenceSystem(string $instanceType): string
    {
        $value = strtolower(trim($instanceType));

        return match (true) {
            str_contains($value, 'anthropic') => 'anthropic',
            str_contains($value, 'openai') => 'openai',
            str_contains($value, 'gemini'), str_contains($value, 'google') => 'vertexai',
            str_contains($value, 'mistral') => 'mistralai',
            str_contains($value, 'cohere') => 'cohere',
            str_contains($value, 'deepseek') => 'deepseek',
            str_contains($value, 'bedrock'), str_contains($value, 'amazon') => 'amazon',
            str_contains($value, 'llama'), str_contains($value, 'meta') => 'meta',
            default => $this->lowCardinality($value, 'unknown'),
        };
    }

    private function openInferenceProvider(string $instanceType): string
    {
        $value = strtolower(trim($instanceType));

        return match (true) {
            str_contains($value, 'gemini'), str_contains($value, 'google') => 'google',
            str_contains($value, 'bedrock'), str_contains($value, 'amazon') => 'aws',
            str_contains($value, 'mistral') => 'mistralai',
            default => $this->providerName($instanceType),
        };
    }

    private function openInferenceAgentSpanKind(string $action): string
    {
        return match (true) {
            $action === 'tool_call' => 'TOOL',
            str_contains($action, 'guardrail'), str_contains($action, 'policy_check') => 'GUARDRAIL',
            str_contains($action, 'evaluat'), str_contains($action, 'quality_check') => 'EVALUATOR',
            default => 'AGENT',
        };
    }

    private function correlationId(string $seed): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            $key = self::class;
        }

        return substr(hash_hmac('sha256', 'session:'.$seed, $key), 0, 24);
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'yes', 'on'], true);
    }

    /**
     * @param  array<string, mixed>  $span
     */
    private function spanHasAttribute(array $span, string $key): bool
    {
        foreach ((array) ($span['attributes'] ?? []) as $attribute) {
            if (($attribute['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $runMap
     */
    private function workflowRootRunId(int $runId, array $runMap): int
    {
        $current = $runId;
        $visited = [];

        while (isset($runMap[$current]) && ! isset($visited[$current])) {
            $visited[$current] = true;
            $parentRunId = (int) ($runMap[$current]['parent_run_id'] ?? 0);
            if ($parentRunId <= 0 || ! isset($runMap[$parentRunId])) {
                break;
            }

            $current = $parentRunId;
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $runMap
     * @param  array<int, array<string, mixed>>  $nodeMap
     */
    private function workflowParentSpanId(array $row, array $runMap, array $nodeMap): ?string
    {
        $parentRunId = (int) ($row['parent_run_id'] ?? 0);
        if ($parentRunId <= 0 || ! isset($runMap[$parentRunId])) {
            return null;
        }

        $parentNodeId = (int) ($row['parent_node_execution_id'] ?? 0);
        if ($parentNodeId > 0 && isset($nodeMap[$parentNodeId])) {
            return $this->workflowNodeSpanId($parentNodeId);
        }

        return $this->workflowRunSpanId($parentRunId);
    }

    private function workflowRunSpanId(int $runId): string
    {
        return $this->hexId('workflow-run-span:'.$runId, 16);
    }

    private function workflowNodeSpanId(int $nodeId): string
    {
        return $this->hexId('workflow-node-span:'.$nodeId, 16);
    }

    private function lowCardinality(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,119}$/', $value) === 1) {
            return $value;
        }

        return $fallback.'-'.substr(hash('sha256', $value), 0, 12);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function timeRange(mixed $createdAt, float $durationMs): array
    {
        try {
            $end = CarbonImmutable::parse((string) $createdAt, config('app.timezone', 'UTC'))->utc();
        } catch (Throwable) {
            $end = CarbonImmutable::now('UTC');
        }

        $endNano = ((int) $end->format('U')) * 1_000_000_000 + ((int) $end->format('u')) * 1000;
        $startNano = max(0, $endNano - (int) round($durationMs * 1_000_000));

        return [(string) $startNano, (string) $endNano];
    }

    /**
     * @return array{0:string,1:string,2:float}
     */
    private function completedTimeRange(mixed $startedAt, mixed $completedAt, array $nodeRows = []): array
    {
        try {
            $start = CarbonImmutable::parse((string) $startedAt, config('app.timezone', 'UTC'))->utc();
            $end = CarbonImmutable::parse((string) $completedAt, config('app.timezone', 'UTC'))->utc();
        } catch (Throwable) {
            $end = CarbonImmutable::now('UTC');
            $start = $end;
        }

        $startNano = $this->carbonNano($start);
        $endNano = max($startNano, $this->carbonNano($end));

        foreach ($nodeRows as $nodeRow) {
            [$nodeStart, $nodeEnd] = $this->startTimeRange(
                $nodeRow['executed_at'] ?? null,
                max(0, (float) ($nodeRow['duration_ms'] ?? 0))
            );
            $startNano = min($startNano, (int) $nodeStart);
            $endNano = max($endNano, (int) $nodeEnd);
        }

        return [
            (string) $startNano,
            (string) $endNano,
            ($endNano - $startNano) / 1_000_000,
        ];
    }

    /** @return array{0:string,1:string} */
    private function startTimeRange(mixed $startedAt, float $durationMs): array
    {
        try {
            $start = CarbonImmutable::parse((string) $startedAt, config('app.timezone', 'UTC'))->utc();
        } catch (Throwable) {
            $start = CarbonImmutable::now('UTC');
        }

        $startNano = $this->carbonNano($start);
        $endNano = $startNano + max(0, (int) round($durationMs * 1_000_000));

        return [(string) $startNano, (string) $endNano];
    }

    private function carbonNano(CarbonImmutable $time): int
    {
        return ((int) $time->format('U')) * 1_000_000_000 + ((int) $time->format('u')) * 1000;
    }

    private function hexId(string $seed, int $length): string
    {
        return substr(hash('sha256', $seed), 0, $length);
    }
}
