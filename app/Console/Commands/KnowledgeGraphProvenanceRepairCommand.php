<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KnowledgeGraphProvenanceRepairCommand extends Command
{
    protected $signature = 'graph:repair-provenance-links
                            {--limit=1000 : Maximum endpoint links to update}
                            {--dry-run : Explicitly preview eligible endpoint-link repairs without writing}
                            {--execute : Apply capped endpoint-link repairs}
                            {--json : Output machine-readable JSON}
                            {--compact : Omit nonessential detail from output}';

    protected $description = 'Dry-run-first repair for knowledge graph triples missing entity endpoint links';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 10000) {
            $this->error('--limit must be between 1 and 10000');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $explicitDryRun = (bool) $this->option('dry-run');
        if ($execute && $explicitDryRun) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $eligible = $this->eligibleCounts();
        $updated = [
            'subject_entity_links' => 0,
            'object_entity_links' => 0,
            'total' => 0,
        ];

        if ($execute) {
            $updated['subject_entity_links'] = $this->repairEndpointLinks('subject', $limit);
            $remaining = max(0, $limit - $updated['subject_entity_links']);
            $updated['object_entity_links'] = $remaining > 0
                ? $this->repairEndpointLinks('object', $remaining)
                : 0;
            $updated['total'] = $updated['subject_entity_links'] + $updated['object_entity_links'];
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $execute ? 'execute' : 'dry_run',
            'dry_run' => ! $execute,
            'explicit_dry_run' => $explicitDryRun,
            'limit' => $limit,
            'status' => $execute ? 'executed' : 'review_required',
            'eligible' => $eligible,
            'updated' => $updated,
            'stopped_reason' => $this->stoppedReason($execute, $eligible['total'], $updated['total'], $limit),
            'posture' => [
                'creates_entities' => false,
                'creates_triples' => false,
                'changes_source_document_id' => false,
                'repairs_active_triples_only' => true,
                'exact_canonical_entity_match_only' => true,
                'capped' => true,
                'requires_execute' => true,
                'raw_graph_rows_included' => false,
            ],
        ];

        if ((bool) $this->option('compact')) {
            $payload['compact'] = true;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Knowledge graph provenance link repair: %s',
            $execute ? 'EXECUTED' : 'DRY RUN'
        ));
        $this->table(
            ['Metric', 'Count'],
            [
                ['eligible subject links', number_format($eligible['subject_entity_links'])],
                ['eligible object links', number_format($eligible['object_entity_links'])],
                ['updated subject links', number_format($updated['subject_entity_links'])],
                ['updated object links', number_format($updated['object_entity_links'])],
            ]
        );

        if (! $execute) {
            $this->warn('Dry run only. Re-run with --execute to apply capped endpoint-link repairs.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{subject_entity_links: int, object_entity_links: int, total: int}
     */
    private function eligibleCounts(): array
    {
        $subject = $this->eligibleEndpointCount('subject');
        $object = $this->eligibleEndpointCount('object');

        return [
            'subject_entity_links' => $subject,
            'object_entity_links' => $object,
            'total' => $subject + $object,
        ];
    }

    private function eligibleEndpointCount(string $side): int
    {
        $columns = $this->endpointColumns($side);
        $row = DB::connection('pgsql_rag')->selectOne(
            "
            SELECT COUNT(*) AS count
              FROM knowledge_graph kg
              JOIN knowledge_graph_entities e
                ON LOWER(e.canonical_name) = LOWER(kg.{$columns['name']})
               AND e.entity_type = kg.{$columns['type']}
             WHERE kg.t_expired IS NULL
               AND kg.{$columns['entity_id']} IS NULL
            "
        );

        return (int) ($row->count ?? 0);
    }

    private function repairEndpointLinks(string $side, int $limit): int
    {
        $columns = $this->endpointColumns($side);
        $nameColumn = $columns['name'];
        $typeColumn = $columns['type'];
        $entityIdColumn = $columns['entity_id'];
        $rows = DB::connection('pgsql_rag')->select(
            "
            UPDATE knowledge_graph kg
               SET {$entityIdColumn} = candidates.entity_id,
                   updated_at = NOW()
              FROM (
                    SELECT kg_inner.id, e.id AS entity_id
                      FROM knowledge_graph kg_inner
                      JOIN knowledge_graph_entities e
                        ON LOWER(e.canonical_name) = LOWER(kg_inner.{$nameColumn})
                       AND e.entity_type = kg_inner.{$typeColumn}
                     WHERE kg_inner.t_expired IS NULL
                       AND kg_inner.{$entityIdColumn} IS NULL
                     ORDER BY kg_inner.id
                     LIMIT ?
              ) AS candidates
             WHERE kg.id = candidates.id
            RETURNING kg.id
            ",
            [$limit]
        );

        return count($rows);
    }

    /**
     * @return array{name: string, type: string, entity_id: string}
     */
    private function endpointColumns(string $side): array
    {
        if ($side === 'subject') {
            return [
                'name' => 'subject',
                'type' => 'subject_type',
                'entity_id' => 'subject_entity_id',
            ];
        }

        return [
            'name' => 'object',
            'type' => 'object_type',
            'entity_id' => 'object_entity_id',
        ];
    }

    private function stoppedReason(bool $execute, int $eligible, int $updated, int $limit): string
    {
        if (! $execute) {
            return 'dry_run';
        }

        if ($updated >= $limit && $eligible > $updated) {
            return 'limit_reached';
        }

        return 'complete_or_no_more_eligible';
    }
}
