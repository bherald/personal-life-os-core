<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AgentTrajectoryService
{
    public const SCHEMA = 'plos.agent_trajectory.v1';

    public const FIXTURE_SCHEMA = 'plos.agent_trajectory_fixture.v1';

    private const TRUST_BOUNDARY = 'redacted_agent_trajectory_not_ground_truth';

    /**
     * Agent/tool entry point: build a redacted trajectory from retained audit
     * evidence. This is read-only and deliberately derives from existing logs.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(array $params): array
    {
        $limit = $this->clampInt($params['limit'] ?? 50, 1, 200);
        $hours = $this->clampInt($params['hours'] ?? $params['window_hours'] ?? 168, 1, 2160);
        $agentId = $this->optionalString($params['agent_id'] ?? $params['_agent_id'] ?? null, 100);
        $sessionId = $this->optionalString($params['session_id'] ?? $params['_session_id'] ?? null, 100);
        $includeReviews = $this->truthy($params['include_reviews'] ?? true);
        $cutoff = now()->subHours($hours)->format('Y-m-d H:i:s');

        $steps = [];
        $reviewIds = [];
        $seenToolKeys = [];

        foreach ($this->executionRows($cutoff, $limit, $agentId, $sessionId) as $row) {
            $context = $this->decodeJson($row->context ?? null);
            $reviewId = $this->reviewIdFromContext($context);
            if ($reviewId !== null) {
                $reviewIds[$reviewId] = true;
            }
            if (($row->action_type ?? null) === 'tool_call') {
                $seenToolKeys[$this->toolKey($row->agent_name ?? null, $row->session_id ?? null, $row->action_detail ?? null)] = true;
            }

            $steps[] = [
                'source_type' => 'agent_execution_log',
                'source_id' => (string) $row->id,
                'occurred_at' => $this->timestampString($row->created_at ?? null),
                'session_id' => $this->redactIdentifier($row->session_id ?? null),
                'agent_id' => $this->redactIdentifier($row->agent_name ?? null),
                'step_type' => (string) ($row->action_type ?? 'event'),
                'tool_name' => $this->redactIdentifier($row->action_detail ?? null),
                'gate' => $this->gateFromExecutionRow($row, $context),
                'risk_level' => $this->normalizeRisk($row->risk_level ?? null),
                'outcome' => $this->normalizeOutcome($row->outcome ?? null, (bool) ($row->success ?? false)),
                'duration_ms' => $this->nullableInt($row->duration_ms ?? null),
                'error_class' => $this->errorClass($this->errorTextFromContext($context), (string) ($row->outcome ?? '')),
                'input_summary' => $this->summary($row->input_summary ?? null),
                'output_summary' => $this->summary($row->output_summary ?? null),
                'metadata' => $this->executionMetadata($row, $context),
            ];
        }

        foreach ($this->mcpRows($cutoff, $limit, $agentId, $sessionId) as $row) {
            if (isset($seenToolKeys[$this->toolKey($row->agent_id ?? null, $row->session_id ?? null, $row->tool_name ?? null)])) {
                continue;
            }

            $steps[] = [
                'source_type' => 'mcp_tool_calls',
                'source_id' => (string) $row->id,
                'occurred_at' => $this->timestampString($row->created_at ?? null),
                'session_id' => $this->redactIdentifier($row->session_id ?? null),
                'agent_id' => $this->redactIdentifier($row->agent_id ?? null),
                'step_type' => 'tool_call',
                'tool_name' => $this->redactIdentifier($row->tool_name ?? null),
                'gate' => $this->gateFromMcpRow($row),
                'risk_level' => 'read',
                'outcome' => ((int) ($row->success ?? 0)) === 1 ? 'success' : 'failure',
                'duration_ms' => $this->nullableInt($row->duration_ms ?? null),
                'error_class' => $this->errorClass($row->error_message ?? null, ((int) ($row->success ?? 0)) === 1 ? 'success' : 'failure'),
                'input_summary' => $this->summary($row->params_summary ?? null),
                'output_summary' => $row->result_size === null ? null : 'result_size_bytes='.(int) $row->result_size,
                'metadata' => $this->compactMetadata([
                    'mcp_server' => $row->mcp_server ?? null,
                    'mcp_tool' => $row->mcp_tool ?? null,
                    'caller' => $row->caller ?? null,
                    'result_size' => $row->result_size ?? null,
                ]),
            ];
        }

        if ($includeReviews) {
            foreach ($this->reviewRows($cutoff, $limit, $agentId, $sessionId, array_keys($reviewIds)) as $row) {
                $steps[] = [
                    'source_type' => 'agent_review_queue',
                    'source_id' => (string) $row->id,
                    'occurred_at' => $this->timestampString($row->reviewed_at ?? $row->updated_at ?? $row->created_at ?? null),
                    'session_id' => null,
                    'agent_id' => $this->redactIdentifier($row->agent_id ?? null),
                    'step_type' => 'review_outcome',
                    'tool_name' => null,
                    'gate' => 'operator_review',
                    'risk_level' => 'write',
                    'outcome' => $this->normalizeReviewOutcome($row->status ?? null),
                    'duration_ms' => null,
                    'error_class' => $this->reviewErrorClass($row),
                    'input_summary' => null,
                    'output_summary' => null,
                    'metadata' => $this->compactMetadata([
                        'review_type' => $row->review_type ?? null,
                        'finding_type' => $row->finding_type ?? null,
                        'confidence' => $row->confidence ?? null,
                        'priority' => $row->priority ?? null,
                        'reviewed' => $row->reviewed_at !== null ? 'true' : 'false',
                    ]),
                ];
            }
        }

        usort($steps, static function (array $a, array $b): int {
            $timeCompare = strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? ''));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return strcmp((string) ($a['source_type'] ?? ''), (string) ($b['source_type'] ?? ''));
        });

        $steps = array_slice(array_values($steps), 0, $limit);
        foreach ($steps as $index => &$step) {
            $step['sequence'] = $index + 1;
        }
        unset($step);

        $summary = $this->summarize($steps);
        $payload = [
            'success' => true,
            'schema' => self::SCHEMA,
            'trust_boundary' => self::TRUST_BOUNDARY,
            'usage_note' => 'Trajectory evidence is redacted operational history. Use it for regression analysis, not as factual ground truth.',
            'filters' => [
                'agent_id' => $this->redactIdentifier($agentId),
                'session_id' => $this->redactIdentifier($sessionId),
                'window_hours' => $hours,
                'limit' => $limit,
                'include_reviews' => $includeReviews,
            ],
            'summary' => $summary,
            'steps' => $steps,
        ];

        if ($this->truthy($params['include_fixture'] ?? false)) {
            $payload['eval_fixture'] = $this->fixtureFromTrajectory($payload, [
                'scenario' => $params['scenario'] ?? null,
            ]);
        }

        $payload['result_text'] = $this->formatResultText($payload);

        return $payload;
    }

    /**
     * Build a compact eval fixture that keeps labels, gates, and outcomes while
     * omitting raw private prompts, summaries, file paths, review text, and row ids.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function exportEvalFixture(array $params): array
    {
        $trajectory = $this->build(array_merge($params, ['include_fixture' => false]));

        return $this->fixtureFromTrajectory($trajectory, $params);
    }

    /**
     * @return array<int, object>
     */
    private function executionRows(string $cutoff, int $limit, ?string $agentId, ?string $sessionId): array
    {
        if (! Schema::hasTable('agent_execution_log')) {
            return [];
        }

        try {
            $query = DB::table('agent_execution_log')
                ->select([
                    'id',
                    'session_id',
                    'agent_name',
                    'action_type',
                    'action_detail',
                    'risk_level',
                    'context',
                    'outcome',
                    'role',
                    'input_summary',
                    'output_summary',
                    'duration_ms',
                    'success',
                    'created_at',
                ])
                ->where('created_at', '>=', $cutoff)
                ->orderByDesc('created_at')
                ->limit($limit);

            $this->applyAgentFilter($query, 'agent_name', $agentId);
            $this->applySessionFilter($query, 'session_id', $sessionId);

            return $query->get()->all();
        } catch (\Throwable $e) {
            Log::debug('AgentTrajectoryService: execution log read failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<int, object>
     */
    private function mcpRows(string $cutoff, int $limit, ?string $agentId, ?string $sessionId): array
    {
        if (! Schema::hasTable('mcp_tool_calls')) {
            return [];
        }

        try {
            $query = DB::table('mcp_tool_calls')
                ->select([
                    'id',
                    'tool_name',
                    'mcp_server',
                    'mcp_tool',
                    'agent_id',
                    'session_id',
                    'caller',
                    'success',
                    'duration_ms',
                    'error_message',
                    'params_summary',
                    'result_size',
                    'created_at',
                ])
                ->where('created_at', '>=', $cutoff)
                ->orderByDesc('created_at')
                ->limit($limit);

            $this->applyAgentFilter($query, 'agent_id', $agentId);
            $this->applySessionFilter($query, 'session_id', $sessionId);

            return $query->get()->all();
        } catch (\Throwable $e) {
            Log::debug('AgentTrajectoryService: mcp tool call read failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, int|string>  $reviewIds
     * @return array<int, object>
     */
    private function reviewRows(string $cutoff, int $limit, ?string $agentId, ?string $sessionId, array $reviewIds): array
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return [];
        }

        if ($sessionId !== null && $reviewIds === []) {
            return [];
        }

        try {
            $query = DB::table('agent_review_queue')
                ->select([
                    'id',
                    'agent_id',
                    'review_type',
                    'finding_type',
                    'confidence',
                    'priority',
                    'status',
                    'reviewed_at',
                    'created_at',
                    'updated_at',
                ]);

            if ($sessionId !== null) {
                $query->whereIn('id', array_map('intval', $reviewIds));
            } else {
                $query->where(function (Builder $builder) use ($cutoff, $reviewIds): void {
                    $builder->where('created_at', '>=', $cutoff)
                        ->orWhere('updated_at', '>=', $cutoff)
                        ->orWhere('reviewed_at', '>=', $cutoff);

                    if ($reviewIds !== []) {
                        $builder->orWhereIn('id', array_map('intval', $reviewIds));
                    }
                });
            }

            $query
                ->orderByDesc('updated_at')
                ->limit($limit);

            $this->applyAgentFilter($query, 'agent_id', $agentId);

            return $query->get()->all();
        } catch (\Throwable $e) {
            Log::debug('AgentTrajectoryService: review queue read failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function applyAgentFilter(Builder $query, string $column, ?string $agentId): void
    {
        if ($agentId !== null) {
            $query->where($column, $agentId);
        }
    }

    private function applySessionFilter(Builder $query, string $column, ?string $sessionId): void
    {
        if ($sessionId !== null) {
            $query->where($column, $sessionId);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function summarize(array $steps): array
    {
        $toolCalls = array_values(array_filter($steps, static fn (array $step): bool => ($step['step_type'] ?? null) === 'tool_call'));
        $reviewItems = array_values(array_filter($steps, static fn (array $step): bool => ($step['step_type'] ?? null) === 'review_outcome'));

        return [
            'step_count' => count($steps),
            'tool_call_count' => count($toolCalls),
            'failed_tool_call_count' => count(array_filter($toolCalls, static fn (array $step): bool => in_array($step['outcome'] ?? null, ['failure', 'timeout', 'skipped'], true))),
            'denied_tool_call_count' => count(array_filter($toolCalls, static fn (array $step): bool => ($step['outcome'] ?? null) === 'denied' || ($step['gate'] ?? null) === 'ds_governance_blocked')),
            'review_item_count' => count($reviewItems),
            'review_completed_count' => count(array_filter($reviewItems, static fn (array $step): bool => in_array($step['outcome'] ?? null, ['approved', 'approved_with_notes', 'rejected'], true))),
            'redacted' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $trajectory
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function fixtureFromTrajectory(array $trajectory, array $params): array
    {
        $steps = is_array($trajectory['steps'] ?? null) ? $trajectory['steps'] : [];
        $cases = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $cases[] = [
                'case_key' => $this->caseKey($step),
                'step_type' => (string) ($step['step_type'] ?? 'event'),
                'tool_name_hash' => $this->hashNullable($step['tool_name'] ?? null),
                'gate' => $step['gate'] ?? null,
                'risk_level' => $step['risk_level'] ?? null,
                'expected_outcome' => $step['outcome'] ?? null,
                'error_class' => $step['error_class'] ?? 'none',
                'source_type' => $step['source_type'] ?? null,
                'has_duration' => isset($step['duration_ms']) && $step['duration_ms'] !== null,
                'has_review_result' => ($step['step_type'] ?? null) === 'review_outcome',
            ];
        }

        return [
            'success' => true,
            'schema' => self::FIXTURE_SCHEMA,
            'source_schema' => $trajectory['schema'] ?? self::SCHEMA,
            'trust_boundary' => 'sanitized_regression_fixture_no_raw_private_content',
            'scenario' => $this->optionalString($params['scenario'] ?? null, 120) ?? 'agent_trajectory_regression',
            'source_hash' => hash('sha256', json_encode($trajectory['filters'] ?? [], JSON_UNESCAPED_SLASHES) ?: ''),
            'summary' => [
                'case_count' => count($cases),
                'failed_or_denied_cases' => count(array_filter($cases, static fn (array $case): bool => in_array($case['expected_outcome'] ?? null, ['failure', 'denied', 'timeout', 'skipped'], true))),
                'review_cases' => count(array_filter($cases, static fn (array $case): bool => $case['has_review_result'] === true)),
                'raw_text_included' => false,
            ],
            'cases' => $cases,
            'omitted_fields' => [
                'agent_id',
                'session_id',
                'source_id',
                'input_summary',
                'output_summary',
                'review_title',
                'review_summary',
                'review_details',
                'raw_prompt',
                'raw_response',
                'file_paths',
                'secrets',
            ],
            'result_text' => 'Sanitized trajectory fixture built with '.count($cases).' cases; raw private text omitted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function gateFromExecutionRow(object $row, array $context): string
    {
        $actionType = (string) ($row->action_type ?? '');
        $outcome = (string) ($row->outcome ?? '');
        $detail = (string) ($row->action_detail ?? '');

        if ($actionType === 'guardrail_check' || str_contains(strtolower($detail), 'guardrail')) {
            return 'guardrail';
        }

        if (isset($context['profile']) || str_contains(strtolower($detail), 'ds governance')) {
            return $outcome === 'denied' ? 'ds_governance_blocked' : 'ds_governance';
        }

        if (($row->risk_level ?? null) === 'blocked' || $outcome === 'denied') {
            return 'blocked';
        }

        return $this->normalizeRisk($row->risk_level ?? null);
    }

    private function gateFromMcpRow(object $row): string
    {
        if (! empty($row->mcp_server) || ! empty($row->mcp_tool)) {
            return 'mcp_bridge';
        }

        return 'registry';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string|int|float|bool|null>
     */
    private function executionMetadata(object $row, array $context): array
    {
        return $this->compactMetadata([
            'role' => $row->role ?? null,
            'context_profile' => $context['profile'] ?? null,
            'tool_class' => $context['tool_class'] ?? null,
            'reason_class' => isset($context['reason']) ? $this->errorClass((string) $context['reason'], (string) ($row->outcome ?? '')) : null,
            'review_id_hash' => $this->hashNullable($this->reviewIdFromContext($context)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reviewIdFromContext(array $context): ?int
    {
        $value = $context['review_id'] ?? $context['review_queue_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function errorTextFromContext(array $context): ?string
    {
        foreach (['error', 'reason', 'message', 'last_error'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                return (string) $context[$key];
            }
        }

        return null;
    }

    private function reviewErrorClass(object $row): string
    {
        return match ($this->normalizeReviewOutcome($row->status ?? null)) {
            'rejected' => 'operator_rejected',
            'quarantined' => 'operator_quarantined',
            'pending' => 'pending_review',
            default => 'none',
        };
    }

    private function errorClass(mixed $error, string $outcome): string
    {
        $text = strtolower((string) ($error ?? ''));
        $outcome = strtolower(trim($outcome));

        if ($outcome === 'denied' || str_contains($text, 'ds governance')) {
            return 'ds_governance_blocked';
        }
        if (str_contains($text, 'guardrail') || str_contains($text, 'blocked by security policy')) {
            return 'guardrail_blocked';
        }
        if ($outcome === 'timeout' || str_contains($text, 'timeout') || str_contains($text, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($text, 'missing required parameter')) {
            return 'missing_required_parameter';
        }
        if (str_contains($text, 'unknown tool')) {
            return 'unknown_tool';
        }
        if (str_contains($text, 'permission') || str_contains($text, 'unauthorized') || str_contains($text, 'forbidden')) {
            return 'permission_denied';
        }
        if (in_array($outcome, ['failure', 'skipped'], true) || $text !== '') {
            return 'tool_execution_error';
        }

        return 'none';
    }

    private function normalizeRisk(mixed $value): string
    {
        $risk = strtolower(trim((string) ($value ?? 'read')));

        return in_array($risk, ['read', 'write', 'destructive', 'blocked'], true) ? $risk : 'read';
    }

    private function normalizeOutcome(mixed $value, bool $success): string
    {
        $outcome = strtolower(trim((string) ($value ?? '')));

        if (in_array($outcome, ['success', 'failure', 'denied', 'timeout', 'skipped'], true)) {
            return $outcome;
        }

        return $success ? 'success' : 'failure';
    }

    private function normalizeReviewOutcome(mixed $value): string
    {
        $status = strtolower(trim((string) ($value ?? 'pending')));

        return in_array($status, ['pending', 'approved', 'approved_with_notes', 'rejected', 'quarantined', 'archived', 'expired'], true)
            ? $status
            : 'pending';
    }

    private function summary(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = $this->compactWhitespace($this->redact((string) $value));
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, 360);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string|int|float|bool|null>
     */
    private function compactMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $clean[$key] = is_string($value)
                ? mb_substr($this->redact($value), 0, 160)
                : $value;
        }

        return $clean;
    }

    private function formatResultText(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        return sprintf(
            'Agent trajectory: steps=%d tool_calls=%d failed=%d denied=%d reviews=%d trust_boundary=%s',
            (int) ($summary['step_count'] ?? 0),
            (int) ($summary['tool_call_count'] ?? 0),
            (int) ($summary['failed_tool_call_count'] ?? 0),
            (int) ($summary['denied_tool_call_count'] ?? 0),
            (int) ($summary['review_item_count'] ?? 0),
            self::TRUST_BOUNDARY,
        );
    }

    private function caseKey(array $step): string
    {
        $basis = [
            $step['source_type'] ?? '',
            $step['step_type'] ?? '',
            $step['tool_name'] ?? '',
            $step['gate'] ?? '',
            $step['risk_level'] ?? '',
            $step['outcome'] ?? '',
            $step['error_class'] ?? '',
        ];

        return 'trj_'.substr(hash('sha256', implode('|', array_map('strval', $basis))), 0, 16);
    }

    private function toolKey(mixed $agentId, mixed $sessionId, mixed $toolName): string
    {
        return implode('|', [
            (string) ($agentId ?? ''),
            (string) ($sessionId ?? ''),
            (string) ($toolName ?? ''),
        ]);
    }

    private function hashNullable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return 'sha256:'.hash('sha256', (string) $value);
    }

    private function redactIdentifier(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($this->redact((string) $value), 0, 120);
    }

    private function redact(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN\s+[A-Z ]*PRIVATE KEY-----.*?-----END\s+[A-Z ]*PRIVATE KEY-----/is', '[REDACTED_PRIVATE_KEY]', $text);
        $text = (string) preg_replace('/\b(?:password|passwd|pwd|api[_-]?key|apikey|secret|token|bearer|authorization)\s*[:=]\s*["\']?[^"\'\s,;{}<>]{3,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{10,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\b[sp]k_(?:live|test)_[A-Za-z0-9]{10,}\b/', '[REDACTED_KEY]', $text);
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9]{20,}\b/', '[REDACTED_KEY]', $text);
        $text = (string) preg_replace('~/home/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);
        $text = (string) preg_replace('~/Users/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);
        $text = (string) preg_replace('/\b[A-Za-z]:\\\\Users\\\\[^\\s"\'<>),;]+/', '[REDACTED_LOCAL_PATH]', $text);

        return $text;
    }

    private function compactWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function optionalString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function timestampString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value) ?: null;
    }
}
