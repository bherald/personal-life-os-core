<?php

namespace App\Console\Commands;

use App\Services\WhiteroomProjectRegistryService;
use Illuminate\Console\Command;

class WhiteroomProjectsCommand extends Command
{
    protected $signature = 'whiteroom:projects
        {--format=human : Output format: human or json}
        {--status=* : Filter by one or more project statuses}
        {--priority=* : Filter by one or more priorities}
        {--registry=* : Filter by registry source, such as curated_whiteroom, monitor_watchlist, or research_watchlist}
        {--source-type=* : Filter by one or more source types, such as repository, paper, or standard}
        {--sidecar-only : Show only projects with sidecar-style policies}';

    protected $description = 'List lawful upstream source review decisions and PLOS adoption gates';

    public function handle(WhiteroomProjectRegistryService $registry): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('Invalid --format. Use human or json.');

            return self::FAILURE;
        }

        $projects = $this->filteredProjects($registry);
        $payload = [
            'schema' => config('whiteroom_projects.schema'),
            'reviewed_at' => config('whiteroom_projects.reviewed_at'),
            'monitor_reviewed_at' => config('whiteroom_projects.monitor_reviewed_at'),
            'research_reviewed_at' => config('whiteroom_projects.research_reviewed_at'),
            'policy' => config('whiteroom_projects.policy'),
            'summary' => $this->summary($projects),
            'projects' => array_values($projects),
        ];

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function filteredProjects(WhiteroomProjectRegistryService $registry): array
    {
        $projects = $registry->all();
        $statuses = $this->normalizedOptionSet('status');
        $priorities = $this->normalizedOptionSet('priority');
        $registries = $this->normalizedOptionSet('registry');
        $sourceTypes = $this->normalizedOptionSet('source-type');
        $sidecarOnly = (bool) $this->option('sidecar-only');

        return array_values(array_filter($projects, function (array $project) use ($statuses, $priorities, $registries, $sourceTypes, $sidecarOnly): bool {
            if ($statuses !== [] && ! in_array(strtolower((string) ($project['status'] ?? '')), $statuses, true)) {
                return false;
            }

            if ($priorities !== [] && ! in_array(strtolower((string) ($project['priority'] ?? '')), $priorities, true)) {
                return false;
            }

            if ($registries !== [] && ! in_array(strtolower((string) ($project['registry'] ?? '')), $registries, true)) {
                return false;
            }

            if ($sourceTypes !== [] && ! in_array(strtolower((string) ($project['source_type'] ?? '')), $sourceTypes, true)) {
                return false;
            }

            if ($sidecarOnly) {
                $policy = strtolower((string) ($project['sidecar_policy'] ?? ''));
                $status = strtolower((string) ($project['status'] ?? ''));

                return str_contains($policy, 'sidecar') || str_contains($status, 'sidecar');
            }

            return true;
        }));
    }

    /**
     * @return array<int, string>
     */
    private function normalizedOptionSet(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            $values = [$values];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($value): string => strtolower(trim((string) $value)),
            $values
        ))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<string, mixed>
     */
    private function summary(array $projects): array
    {
        $byStatus = [];
        $byPriority = [];
        $byRegistry = [];
        $bySourceType = [];

        foreach ($projects as $project) {
            $status = (string) ($project['status'] ?? 'unknown');
            $priority = (string) ($project['priority'] ?? 'unknown');
            $registry = (string) ($project['registry'] ?? 'unknown');
            $sourceType = (string) ($project['source_type'] ?? 'unknown');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $byPriority[$priority] = ($byPriority[$priority] ?? 0) + 1;
            $byRegistry[$registry] = ($byRegistry[$registry] ?? 0) + 1;
            $bySourceType[$sourceType] = ($bySourceType[$sourceType] ?? 0) + 1;
        }

        ksort($byStatus);
        ksort($byPriority);
        ksort($byRegistry);
        ksort($bySourceType);

        return [
            'total' => count($projects),
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'by_registry' => $byRegistry,
            'by_source_type' => $bySourceType,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->info('Upstream source registry');
        $this->line('  Reviewed: '.($payload['reviewed_at'] ?? 'unknown'));
        $this->line('  Monitor refreshed: '.($payload['monitor_reviewed_at'] ?? 'unknown'));
        $this->line('  Research refreshed: '.($payload['research_reviewed_at'] ?? 'unknown'));
        $this->line('  Sources: '.($payload['summary']['total'] ?? 0));
        $this->line('  Default runtime: '.($payload['policy']['default_runtime'] ?? 'unknown'));
        $this->newLine();

        foreach ($payload['projects'] as $project) {
            $this->line(sprintf(
                '[%s] %s - %s (%s)',
                $project['priority'] ?? 'unknown',
                $project['name'] ?? $project['key'] ?? 'unknown',
                $project['status'] ?? 'unknown',
                $project['registry'] ?? 'unknown'
            ));
            $this->line('  Source: '.($project['source_url'] ?? $project['repo'] ?? ''));
            if (! empty($project['identifier'])) {
                $this->line('  Identifier: '.($project['identifier_scheme'] ?? 'unknown').':'.($project['identifier'] ?? ''));
            }
            $this->line('  Fit: '.($project['fit'] ?? ''));
            $this->line('  PLOS: '.($project['plos_surface'] ?? ''));
            $this->line('  Next: '.($project['next_action'] ?? ''));
        }
    }
}
