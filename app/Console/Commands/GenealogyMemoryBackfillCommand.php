<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMCPService;
use Illuminate\Console\Command;

class GenealogyMemoryBackfillCommand extends Command
{
    protected $signature = 'genealogy:memory-backfill
        {--tree=all : Tree ID to process, or all}
        {--lanes=all : Lanes: all, canonical_lessons, health_audit, media_intake, source_media_outcomes, review_decisions, review_packets}
        {--limit=25 : Maximum candidates per lane}
        {--confirm : Apply writes; without this option the command runs as a dry-run}
        {--dry-run : Force preview mode even when --confirm is present}
        {--json : Emit compact JSON summary}
        {--compact : With --json, emit aggregate-only scheduled-output JSON without raw error text}';

    protected $description = 'Backfill local Genea learning memory from health, intake, review, and canonical lesson signals';

    public function handle(GenealogyMCPService $genealogy): int
    {
        try {
            $treeId = $this->treeIdOption();
        } catch (\InvalidArgumentException $e) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'tool' => 'memory_backfill_batch',
                    'success' => false,
                    'dry_run' => true,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $confirm = (bool) $this->option('confirm');
        $dryRun = (bool) $this->option('dry-run') || ! $confirm;

        $result = $genealogy->memory_backfill_batch(
            tree_id: $treeId,
            lanes: (string) $this->option('lanes'),
            limit: $limit,
            dry_run: $dryRun,
            confirm: $confirm && ! $dryRun,
            actor: 'genealogy:memory-backfill'
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $this->compactResult($result, (bool) $this->option('compact')),
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));
        } else {
            $this->line(sprintf(
                'Genea memory backfill: %s | dry_run=%s | recorded=%d | candidates=%d | errors=%d',
                ($result['success'] ?? false) ? 'ok' : 'failed',
                ($result['dry_run'] ?? true) ? 'true' : 'false',
                (int) ($result['summary']['recorded_count'] ?? 0),
                (int) ($result['summary']['candidate_count'] ?? 0),
                (int) ($result['summary']['error_count'] ?? 0)
            ));

            if (! ($result['success'] ?? false) && isset($result['error'])) {
                $this->error((string) $result['error']);
            }
        }

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function treeIdOption(): ?int
    {
        $tree = strtolower(trim((string) $this->option('tree')));
        if ($tree === '' || $tree === 'all' || $tree === '0') {
            return null;
        }

        if (! ctype_digit($tree)) {
            throw new \InvalidArgumentException('Invalid --tree value. Use a positive tree ID or all.');
        }

        return (int) $tree;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function compactResult(array $result, bool $scheduledCompact = false): array
    {
        $payload = [
            'tool' => $result['tool'] ?? 'memory_backfill_batch',
            'compact' => $scheduledCompact,
            'success' => (bool) ($result['success'] ?? false),
            'dry_run' => (bool) ($result['dry_run'] ?? true),
            'lanes' => $result['lanes'] ?? [],
            'summary' => $result['summary'] ?? [],
            'timestamp' => $result['timestamp'] ?? now()->toIso8601String(),
        ];

        if ($scheduledCompact) {
            $payload['tree_scope'] = $this->compactTreeScope($result['tree_id'] ?? null);
            $payload['errors'] = $this->compactErrorSummary($result);
            $payload['error'] = isset($result['error']) ? $this->errorCode((string) $result['error']) : null;
            $payload['posture'] = [
                'aggregate_only' => true,
                'tree_identifiers_included' => false,
                'runs_included' => false,
                'memory_ids_included' => false,
                'source_ids_included' => false,
                'person_ids_included' => false,
                'raw_error_text_included' => false,
                'raw_lane_payloads_included' => false,
            ];

            return $payload;
        }

        $payload['tree_id'] = $result['tree_id'] ?? null;
        $payload['errors'] = $this->compactErrors($result);
        $payload['error'] = $result['error'] ?? null;

        return $payload;
    }

    private function compactTreeScope(mixed $treeId): string
    {
        if ($treeId === null || $treeId === '' || $treeId === 'all') {
            return 'all_trees';
        }

        return 'single_tree';
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function compactErrors(array $result): array
    {
        $errors = [];
        foreach ((array) ($result['runs'] ?? []) as $run) {
            if (! is_array($run)) {
                continue;
            }

            foreach ((array) ($run['errors'] ?? []) as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $errors[] = [
                    'tree_id' => $run['tree_id'] ?? null,
                    'lane' => $error['lane'] ?? null,
                    'error' => $error['error'] ?? null,
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function compactErrorSummary(array $result): array
    {
        $errors = $this->compactErrors($result);
        $byLane = [];
        $codes = [];

        foreach ($errors as $error) {
            $lane = is_scalar($error['lane'] ?? null) && (string) $error['lane'] !== ''
                ? (string) $error['lane']
                : 'unknown';
            $code = $this->errorCode((string) ($error['error'] ?? 'unknown'));

            $byLane[$lane] = ($byLane[$lane] ?? 0) + 1;
            $codes[$code] = ($codes[$code] ?? 0) + 1;
        }

        ksort($byLane);
        ksort($codes);

        return [
            'count' => count($errors),
            'by_lane' => $byLane,
            'codes' => $codes,
        ];
    }

    private function errorCode(string $error): string
    {
        $error = strtolower(trim($error));

        if ($error === '') {
            return 'unknown_error';
        }

        if (str_contains($error, 'missing') && str_contains($error, 'table')) {
            return 'missing_table';
        }

        if (str_contains($error, 'permission') || str_contains($error, 'denied')) {
            return 'permission_denied';
        }

        if (str_contains($error, 'timeout') || str_contains($error, 'timed out')) {
            return 'timeout';
        }

        if (str_contains($error, 'connection') || str_contains($error, 'database')) {
            return 'connection_error';
        }

        return 'lane_error';
    }
}
