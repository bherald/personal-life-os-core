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
        {--json : Emit compact JSON summary}';

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
            $this->line(json_encode($this->compactResult($result), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
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
    private function compactResult(array $result): array
    {
        return [
            'tool' => $result['tool'] ?? 'memory_backfill_batch',
            'success' => (bool) ($result['success'] ?? false),
            'dry_run' => (bool) ($result['dry_run'] ?? true),
            'tree_id' => $result['tree_id'] ?? null,
            'lanes' => $result['lanes'] ?? [],
            'summary' => $result['summary'] ?? [],
            'errors' => $this->compactErrors($result),
            'error' => $result['error'] ?? null,
            'timestamp' => $result['timestamp'] ?? now()->toIso8601String(),
        ];
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
}
