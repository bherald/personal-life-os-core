<?php

namespace App\Console\Commands;

use App\Services\ResearchEnhancementsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Research Tools Command
 *
 * Usage:
 *   php artisan research:tools --archive-sources --topic=1
 *   php artisan research:tools --expand-query --query="climate change effects"
 *   php artisan research:tools --confidence --topic=1
 *   php artisan research:tools --backoff-status
 */
class ResearchToolsCommand extends Command
{
    protected $signature = 'research:tools
        {--archive-sources : Archive research source URLs to Archive.org}
        {--expand-query : Generate query variants via LLM}
        {--confidence : Calculate confidence score for topic results}
        {--backoff-status : Show circuit breaker status for all engines}
        {--topic= : Research topic ID}
        {--query= : Search query for expansion}
        {--dry-run : Preview without executing}';

    protected $description = 'Research tools: archiving, query expansion, confidence scoring, circuit breaker status';

    public function handle(): int
    {
        $service = app(ResearchEnhancementsService::class);

        if ($this->option('archive-sources')) {
            return $this->archiveSources($service);
        }
        if ($this->option('expand-query')) {
            return $this->expandQuery($service);
        }
        if ($this->option('confidence')) {
            return $this->showConfidence($service);
        }
        if ($this->option('backoff-status')) {
            return $this->backoffStatus($service);
        }

        $this->info('Usage: research:tools --archive-sources|--expand-query|--confidence|--backoff-status');

        return self::SUCCESS;
    }

    private function archiveSources(ResearchEnhancementsService $service): int
    {
        $topicId = $this->option('topic');
        if (! $topicId) {
            $this->error('--topic is required');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $stats = $service->getArchiveStats();
            $this->info("Total source URLs: {$stats['total_source_urls']}");
            $this->warn('Dry run - no archiving performed.');

            return self::SUCCESS;
        }

        $this->info("Archiving sources for topic {$topicId}...");
        $result = $service->archiveResearchSources((int) $topicId);

        $this->info("Total: {$result['total']}, Archived: {$result['archived']}, Already archived: {$result['already_archived']}, Failed: {$result['failed']}");

        return self::SUCCESS;
    }

    private function expandQuery(ResearchEnhancementsService $service): int
    {
        $query = $this->option('query');
        if (! $query) {
            $this->error('--query is required');

            return self::FAILURE;
        }

        $this->info("Expanding query: {$query}");
        $result = $service->expandQuery($query);

        $this->info("Generated {$result['count']} variants:");
        foreach ($result['variants'] as $i => $variant) {
            $this->line('  '.($i + 1).". {$variant}");
        }

        return self::SUCCESS;
    }

    private function showConfidence(ResearchEnhancementsService $service): int
    {
        $topicId = $this->option('topic');
        if (! $topicId) {
            $this->error('--topic is required');

            return self::FAILURE;
        }

        $results = DB::connection('pgsql_rag')->select(
            'SELECT * FROM research_results WHERE research_topic_id = ? ORDER BY created_at DESC LIMIT 50',
            [(int) $topicId]
        );

        $resultsArray = array_map(function ($r) {
            return [
                'url' => $r->url ?? null,
                'extracted_facts' => $r->extracted_facts ?? '[]',
                'created_at' => $r->created_at ?? null,
                'evidence_count' => 0,
            ];
        }, $results);

        $confidence = $service->calculateConfidence($resultsArray);

        $this->info("Confidence for topic {$topicId}: {$confidence['score']} ({$confidence['level']})");
        $this->line("  Source diversity: {$confidence['components']['source_diversity']}");
        $this->line("  Verification depth: {$confidence['components']['verification_depth']}");
        $this->line("  Recency: {$confidence['components']['recency']}");
        $this->line("  Agreement: {$confidence['components']['agreement']}");

        return self::SUCCESS;
    }

    private function backoffStatus(ResearchEnhancementsService $service): int
    {
        // DuckDuckGo search integration was removed after repeated reliability
        // failures. BannedExternalPatternsRule (phpstan.neon) guards against
        // reintroduction.
        $engines = ['startpage', 'searx', 'mojeek', 'qwant', 'newsapi', 'wikipedia', 'searxng'];

        $rows = [];
        foreach ($engines as $engine) {
            $status = $service->getCircuitBreakerStatus($engine);
            $rows[] = [
                $status['engine'],
                $status['state'],
                $status['failures'],
                $status['last_failure'] ? substr($status['last_failure'], 0, 19) : 'never',
                $status['next_retry'] ?? '-',
            ];
        }

        $this->table(['Engine', 'State', 'Failures', 'Last Failure', 'Next Retry'], $rows);

        return self::SUCCESS;
    }
}
