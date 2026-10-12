<?php

namespace App\Console\Commands;

use App\Services\RaptorCommunityLinkRuntimePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GR-6: RAPTOR-Graph Bridge — link RAPTOR summaries to KG communities
 *
 * For each RAPTOR summary, traces the path:
 *   raptor_summaries.document_id
 *     → knowledge_graph.source_document_id   (KG triples from that doc)
 *     → knowledge_graph.subject_entity_id    (entities mentioned)
 *     → knowledge_graph_entities.primary_community_id  (their community)
 *
 * The community with the most entity votes wins and is written to
 * raptor_summaries.kg_community_id.
 *
 * Run after: graph:detect-communities
 * Run before (or as part of): deepSearch() graph-aware retrieval
 */
class RaptorLinkCommunitiesCommand extends Command
{
    protected $signature = 'rag:link-raptor-communities
        {--limit= : Explicit maximum summaries to inspect; required outside stats mode}
        {--execute : Write resolved community links}
        {--force : Re-link already-linked summaries}
        {--stats : Show linkage statistics and exit}
        {--dry-run : Explicitly select read-only preview mode}';

    protected $description = 'Link RAPTOR summaries to their dominant KG communities (GR-6)';

    public function handle(RaptorCommunityLinkRuntimePolicyService $runtimePolicy): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $execute = (bool) $this->option('execute');
        $explicitDryRun = (bool) $this->option('dry-run');
        if ($execute && $explicitDryRun) {
            $this->error('--execute and --dry-run cannot be combined.');

            return self::INVALID;
        }

        try {
            $policy = $runtimePolicy->required();
            $limit = $runtimePolicy->resolveLimit($this->option('limit'), $policy);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $force = (bool) $this->option('force');
        $dryRun = ! $execute;

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be written');
        }

        // Select summaries to process
        $whereClause = $force ? '' : 'WHERE rs.kg_community_id IS NULL';

        $summaries = DB::connection('pgsql_rag')->select("
            SELECT rs.id, rs.document_id, rs.level, rs.level_name
            FROM raptor_summaries rs
            {$whereClause}
            ORDER BY rs.created_at DESC
            LIMIT ?
        ", [$limit]);

        if (empty($summaries)) {
            $this->info('No RAPTOR summaries pending community linkage.');

            return Command::SUCCESS;
        }

        $pending = DB::connection('pgsql_rag')->selectOne("
            SELECT COUNT(*) as cnt FROM raptor_summaries rs {$whereClause}
        ")->cnt;

        $this->info(sprintf(
            'Processing %d of %d pending summaries...',
            count($summaries),
            $pending
        ));

        $stats = ['linked' => 0, 'no_community' => 0, 'skipped' => 0];

        $bar = $this->output->createProgressBar(count($summaries));
        $bar->start();

        foreach ($summaries as $summary) {
            $communityId = $this->findDominantCommunity(
                (int) $summary->document_id,
                $policy['minimum_entity_votes'],
            );

            if ($communityId === null) {
                $stats['no_community']++;
            } else {
                if (! $dryRun) {
                    DB::connection('pgsql_rag')->update(
                        'UPDATE raptor_summaries SET kg_community_id = ? WHERE id = ?',
                        [$communityId, $summary->id]
                    );
                }
                $stats['linked']++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('RAPTOR-Graph Bridge Complete:');
        $this->table(
            ['Metric', 'Value'],
            [
                [$dryRun ? 'Summaries Would Link' : 'Summaries Linked', $stats['linked']],
                ['No Community Found',      $stats['no_community']],
                ['Remaining Unlinked',      $pending - count($summaries)],
            ]
        );

        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $dryRun ? 0 : $stats['linked']));

        return Command::SUCCESS;
    }

    /**
     * Find the dominant KG community for a given RAG document.
     *
     * Traces: document → KG triples → entity community votes → majority community.
     * Returns null if no entities or no community associations exist.
     */
    private function findDominantCommunity(int $documentId, int $minimumEntityVotes): ?int
    {
        $row = DB::connection('pgsql_rag')->selectOne('
            SELECT kge.primary_community_id, COUNT(*) AS votes
            FROM knowledge_graph kg
            JOIN knowledge_graph_entities kge
                ON kge.id = kg.subject_entity_id
            WHERE kg.source_document_id = ?
              AND kge.primary_community_id IS NOT NULL
            GROUP BY kge.primary_community_id
            ORDER BY votes DESC
            LIMIT 1
        ', [$documentId]);

        if (! $row || (int) $row->votes < $minimumEntityVotes) {
            return null;
        }

        return (int) $row->primary_community_id;
    }

    private function showStats(): int
    {
        $stats = DB::connection('pgsql_rag')->selectOne('
            SELECT
                COUNT(*) AS total_summaries,
                COUNT(kg_community_id) AS linked,
                COUNT(*) FILTER (WHERE kg_community_id IS NULL) AS unlinked,
                COUNT(DISTINCT kg_community_id) AS distinct_communities
            FROM raptor_summaries
        ');

        $levelStats = DB::connection('pgsql_rag')->select('
            SELECT
                level,
                level_name,
                COUNT(*) AS total,
                COUNT(kg_community_id) AS linked
            FROM raptor_summaries
            GROUP BY level, level_name
            ORDER BY level
        ');

        $this->info('RAPTOR-Graph Bridge Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Summaries',       number_format($stats->total_summaries)],
                ['Linked to Community',   number_format($stats->linked)],
                ['Unlinked',              number_format($stats->unlinked)],
                ['Distinct Communities',  number_format($stats->distinct_communities)],
            ]
        );

        if (! empty($levelStats)) {
            $this->info('By Level:');
            $rows = [];
            foreach ($levelStats as $row) {
                $pct = $row->total > 0 ? round($row->linked / $row->total * 100) : 0;
                $rows[] = [$row->level, $row->level_name, $row->total, $row->linked, "{$pct}%"];
            }
            $this->table(['Level', 'Name', 'Total', 'Linked', 'Coverage'], $rows);
        }

        return Command::SUCCESS;
    }
}
