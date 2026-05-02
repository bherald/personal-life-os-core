<?php

namespace App\Services\Scheduler;

/**
 * APL #10 / row 8C — queue-placement review policy (pure analyzer).
 *
 * Given a list of Job files + their resolved queue, classify each against
 * the documented queue placement policy:
 *
 *  - default       → short, bursty, operator-facing ops work
 *  - high          → latency-sensitive (scheduler ticks, daily report)
 *  - low           → nice-to-have background; safe to defer
 *  - long-running  → file/AI/RAG/scan work that holds a worker > 30s
 *  - workflow      → workflow node execution (fan-out/fan-in)
 *  - speculative   → agent speculative branches
 *
 * The analyzer takes job descriptors (filename + declared queue + inferred
 * traits) and returns per-job recommendations. It is deterministic, pure
 * PHP, and has no dependencies on the DB or Horizon — so it can unit-test
 * cleanly and run as either an artisan command or a scheduled audit.
 */
class QueuePlacementAuditor
{
    /**
     * @param array<int, array{name:string, declared_queue:string, content:string}> $jobs
     * @return array<int, array{name:string, declared_queue:string, recommended_queue:string, reason:string, severity:string}>
     */
    public function audit(array $jobs): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $name = $job['name'] ?? '';
            $declared = $job['declared_queue'] ?? 'default';
            $content = $job['content'] ?? '';

            $recommended = $this->recommendQueue($name, $content);
            $severity = $this->severity($declared, $recommended);

            $results[] = [
                'name' => $name,
                'declared_queue' => $declared,
                'recommended_queue' => $recommended,
                'reason' => $this->reason($name, $content),
                'severity' => $severity,
            ];
        }

        return $results;
    }

    private function recommendQueue(string $name, string $content): string
    {
        $n = strtolower($name);

        if ($this->looksLikeWorkflowNode($name, $content)) {
            return 'workflow';
        }

        if ($this->looksLikeSpeculative($name)) {
            return 'speculative';
        }

        if ($this->looksLikeLatencySensitive($name)) {
            return 'high';
        }

        if ($this->looksLikeLongRunning($name, $content)) {
            return 'long-running';
        }

        return 'default';
    }

    private function looksLikeWorkflowNode(string $name, string $content): bool
    {
        return str_contains($name, 'Workflow')
            || str_contains($name, 'Node')
            || str_contains($content, 'WorkflowExecution');
    }

    private function looksLikeSpeculative(string $name): bool
    {
        return str_contains($name, 'Speculative');
    }

    private function looksLikeLongRunning(string $name, string $content): bool
    {
        $tokens = $this->camelCaseTokens($name);
        $markers = ['scan', 'rag', 'ai', 'face', 'thumbnail', 'agent', 'pdf', 'export', 'import', 'genealogy', 'research', 'broker', 'discovery', 'mission', 'attachment', 'catalog', 'autotag'];
        foreach ($markers as $marker) {
            if (in_array($marker, $tokens, true)) {
                return true;
            }
        }
        // Content-level signals: explicit long timeout.
        if (str_contains($content, 'public $timeout = ') && preg_match('~public \$timeout = (\d+)~', $content, $m) && (int) $m[1] > 60) {
            return true;
        }

        return false;
    }

    /**
     * Split CamelCase like 'ExecuteFileRegistryScan' into ['execute','file','registry','scan'].
     * Handles acronym runs: 'AIAutoTagJob' → ['ai','auto','tag','job'] via the `[A-Z]+(?=[A-Z][a-z])`
     * alternation, which matches an uppercase run followed by a CamelCase word head.
     */
    private function camelCaseTokens(string $name): array
    {
        $spaced = preg_replace('~([A-Z]+)(?=[A-Z][a-z])|([a-z0-9])(?=[A-Z])~', '$1$2 ', $name);
        $tokens = preg_split('~[\s_]+~', strtolower((string) $spaced), -1, PREG_SPLIT_NO_EMPTY);
        return $tokens ?: [];
    }

    private function looksLikeLatencySensitive(string $name): bool
    {
        foreach (['OpsMaintenance', 'DailyDigest', 'SchedulerHeartbeat', 'HealthGate', 'Pushover'] as $marker) {
            if (str_contains($name, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function reason(string $name, string $content): string
    {
        if ($this->looksLikeWorkflowNode($name, $content)) return 'workflow execution (fan-out/fan-in)';
        if ($this->looksLikeSpeculative($name)) return 'speculative agent branch';
        if ($this->looksLikeLatencySensitive($name)) return 'latency-sensitive (ops/notify path)';
        if ($this->looksLikeLongRunning($name, $content)) return 'long-running (scan/AI/RAG/file/agent class)';
        return 'short ops work';
    }

    private function severity(string $declared, string $recommended): string
    {
        if ($declared === $recommended) {
            return 'ok';
        }
        // Moving TO long-running from default is HIGH (starvation risk mitigated).
        if ($declared === 'default' && $recommended === 'long-running') {
            return 'high';
        }
        // Moving off long-running onto default is MEDIUM (may want to stay long-running if in doubt).
        if ($declared === 'long-running' && $recommended === 'default') {
            return 'medium';
        }
        return 'medium';
    }
}
