<?php

namespace App\Services;

class AgentMemoryPhaseTelemetryService
{
    public const SCHEMA = 'plos.agent_memory_retrieval.v1';

    private const SOURCES = [
        'gating',
        'rag',
        'procedural',
        'episodic',
        'cross_agent',
        'run_memory',
    ];

    public function __construct(private readonly AgentAuditService $audit) {}

    /**
     * Measure one content-free memory retrieval phase while preserving failures.
     *
     * @param  array<int, array<string, bool|float|int|string>>  $metrics
     */
    public function measure(string $source, bool $attempted, callable $callback, array &$metrics): mixed
    {
        $source = in_array($source, self::SOURCES, true) ? $source : 'unknown';

        if (! $attempted) {
            $metrics[] = $this->metric($source, false, false, 0.0, 0, 'skipped');

            return null;
        }

        $startedAt = hrtime(true);
        $value = null;
        $outcome = 'success';

        try {
            $value = $callback();

            return $value;
        } catch (\Throwable $e) {
            $outcome = 'failure';

            throw $e;
        } finally {
            $included = $source !== 'gating' && (is_string($value)
                ? trim($value) !== ''
                : (is_array($value) && $value !== []));
            $metrics[] = $this->metric(
                $source,
                true,
                $included,
                (hrtime(true) - $startedAt) / 1_000_000,
                is_string($value) ? mb_strlen($value) : 0,
                $outcome,
            );
        }
    }

    /**
     * Persist one aggregate prompt-build receipt without memory or prompt text.
     *
     * @param  array<int, array<string, bool|float|int|string>>  $metrics
     */
    public function record(string $agentName, ?string $sessionId, array $metrics): void
    {
        if (! (bool) config('agent_memory.telemetry.enabled', true) || $metrics === []) {
            return;
        }

        $attempted = count(array_filter($metrics, fn (array $metric): bool => (bool) $metric['attempted']));
        $included = count(array_filter($metrics, fn (array $metric): bool => (bool) $metric['included']));
        $failures = count(array_filter($metrics, fn (array $metric): bool => $metric['outcome'] === 'failure'));
        $durationMs = round(array_sum(array_column($metrics, 'duration_ms')), 3);
        $contextChars = (int) array_sum(array_column($metrics, 'context_chars'));

        $this->audit->record(
            actionType: 'memory_retrieval',
            sessionId: $sessionId,
            agentName: $agentName,
            actionDetail: 'prompt_memory_bundle',
            riskLevel: 'read',
            durationMs: $durationMs,
            outcome: $failures === 0 ? 'success' : 'failure',
            context: [
                'schema' => self::SCHEMA,
                'content_free' => true,
                'compression_applied' => false,
                'sources_attempted' => $attempted,
                'sources_included' => $included,
                'phase_failures' => $failures,
                'context_chars' => $contextChars,
                'phases' => array_values($metrics),
            ],
        );
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function metric(
        string $source,
        bool $attempted,
        bool $included,
        float $durationMs,
        int $contextChars,
        string $outcome,
    ): array {
        return [
            'source' => $source,
            'attempted' => $attempted,
            'included' => $included,
            'duration_ms' => round($durationMs, 3),
            'context_chars' => $contextChars,
            'outcome' => $outcome,
        ];
    }
}
