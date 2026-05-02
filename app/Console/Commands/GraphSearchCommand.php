<?php

namespace App\Console\Commands;

use App\Services\GraphSearchService;
use App\Services\GraphFusionService;
use App\Services\RAGService;
use Illuminate\Console\Command;

class GraphSearchCommand extends Command
{
    protected $signature = 'graph:search
        {query : Search query text}
        {--mode=local : Search mode: local, global, drift, fused}
        {--limit=5 : Max results}
        {--alpha=0.5 : Vector vs graph weight for fused mode (0=all graph, 1=all vector)}
        {--entities-only : Only show entity extraction, no search}';

    protected $description = 'Search using GraphRAG (local/global/drift/fused modes)';

    public function handle(GraphSearchService $graphSearch, RAGService $ragService): int
    {
        $query = $this->argument('query');
        $mode = $this->option('mode');
        $limit = (int) $this->option('limit');
        $alpha = (float) $this->option('alpha');

        if ($this->option('entities-only')) {
            $entities = $graphSearch->extractQueryEntities($query);
            $this->info('Extracted entities: ' . (empty($entities) ? '(none)' : implode(', ', $entities)));
            return Command::SUCCESS;
        }

        if ($mode === 'fused') {
            $this->info("Running fused deep search (alpha={$alpha}, mode=local)...");
            $result = $ragService->deepSearch(
                query: $query,
                topN: $limit,
                useGraph: true,
                graphMode: 'local',
                graphAlpha: $alpha,
            );

            $this->info("Results: " . count($result['results']));
            $this->info("Graph results: " . count($result['graph_results']));
            $this->newLine();

            foreach (array_slice($result['results'], 0, $limit) as $i => $r) {
                $doc = $r['document'];
                $sim = round($r['similarity'] ?? 0, 4);
                $source = $r['fusion_source'] ?? 'vector';
                $boost = !empty($r['graph_boost']) ? ' [GRAPH-BOOSTED]' : '';
                $this->line(($i + 1) . ". [{$sim}] {$doc->title} ({$source}){$boost}");
            }

            return Command::SUCCESS;
        }

        $this->info("Running {$mode} graph search...");

        $results = match ($mode) {
            'global' => $graphSearch->globalSearch($query, $limit),
            'drift' => $graphSearch->driftSearch($query, $limit),
            default => $graphSearch->localSearch($query, $limit),
        };

        if (empty($results)) {
            $this->warn('No results found.');
            $entities = $graphSearch->extractQueryEntities($query);
            $this->line('Extracted entities: ' . (empty($entities) ? '(none)' : implode(', ', $entities)));
            return Command::SUCCESS;
        }

        $this->info(count($results) . " results:");
        $this->newLine();

        $rows = [];
        foreach ($results as $i => $r) {
            $doc = $r['document'] ?? null;
            $title = $doc ? substr($doc->title ?? '(untitled)', 0, 50) : '(community report)';
            $sim = round($r['similarity'] ?? 0, 4);
            $source = $r['graph_source'] ?? $mode;
            $entities = implode(', ', array_slice($r['graph_entities'] ?? [], 0, 3));
            $report = !empty($r['report']) ? substr($r['report']['title'] ?? '', 0, 40) : '';

            $rows[] = [$i + 1, $sim, $source, $title, $entities ?: $report];
        }

        $this->table(['#', 'Score', 'Source', 'Document', 'Entities/Report'], $rows);

        return Command::SUCCESS;
    }
}
