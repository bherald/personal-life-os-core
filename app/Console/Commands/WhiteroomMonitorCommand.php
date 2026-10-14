<?php

namespace App\Console\Commands;

use App\Services\UpstreamSourceDriftRuntimePolicyService;
use App\Services\UpstreamSourceDriftService;
use App\Services\WhiteroomProjectRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class WhiteroomMonitorCommand extends Command
{
    protected $signature = 'whiteroom:monitor
        {--format=human : Output format: human or json}
        {--area=* : Filter by one or more monitor area keys}
        {--status=* : Filter by one or more project statuses}
        {--priority=* : Filter by one or more priorities}
        {--registry=* : Filter by registry source}
        {--source-type=* : Filter by one or more source types, such as repository, paper, or standard}
        {--due : Show only sources due for review}
        {--prepare : Persist bounded current metadata checks and backend changed-source preparation records}
        {--compact : With --format=json, emit scheduler-safe aggregate output}
        {--limit=0 : Limit returned projects, 0 means no limit}
        {--as-of= : Reference date for due calculations, YYYY-MM-DD}';

    protected $description = 'List upstream repositories and research sources, review cadence, signals, and next actions';

    protected $aliases = ['upstream:monitor'];

    public function handle(WhiteroomProjectRegistryService $registry): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('Invalid --format. Use human or json.');

            return self::FAILURE;
        }

        if ((bool) $this->option('compact') && $format !== 'json') {
            $this->error('The --compact option is only supported with --format=json.');

            return self::FAILURE;
        }

        $asOf = trim((string) $this->option('as-of'));
        $asOf = $asOf === '' ? CarbonImmutable::now()->toDateString() : $asOf;
        try {
            CarbonImmutable::parse($asOf);
        } catch (\Throwable) {
            $this->error('Invalid --as-of. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $this->error('Invalid --limit. Use 0 or a positive integer.');

            return self::FAILURE;
        }

        if ((bool) $this->option('prepare')) {
            if ($asOf !== CarbonImmutable::now()->toDateString() || $this->option('due') || $limit !== 0) {
                $this->error('Preparation uses current time, durable cadence and the table-owned limit; omit --due, --as-of and --limit.');

                return self::FAILURE;
            }
            try {
                $result = app(UpstreamSourceDriftService::class)->prepareScheduled(
                    $this->filteredProjects($registry, $asOf),
                    app(UpstreamSourceDriftRuntimePolicyService::class)->preparation(),
                );
                if ((bool) $this->option('compact')) {
                    unset($result['checks']);
                }
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('Upstream preparation held: source, storage or policy qualification failed.');

                return self::FAILURE;
            }
        }

        $projects = $this->filteredProjects($registry, $asOf);
        $this->sortProjects($projects);
        $filteredTotal = count($projects);

        if ($limit > 0) {
            $projects = array_slice($projects, 0, $limit);
        }

        $payload = [
            'schema' => 'plos_whiteroom_monitor.v1',
            'as_of' => $asOf,
            'reviewed_at' => config('whiteroom_projects.reviewed_at'),
            'monitor_reviewed_at' => config('whiteroom_projects.monitor_reviewed_at'),
            'research_reviewed_at' => config('whiteroom_projects.research_reviewed_at'),
            'policy' => config('whiteroom_projects.policy'),
            'summary' => $this->summary($projects),
            'filtered_total' => $filteredTotal,
            'omitted_by_limit' => max(0, $filteredTotal - count($projects)),
            'projects' => array_values($projects),
        ];

        if ((bool) $this->option('compact')) {
            $payload = $this->compactPayload($payload);
        }

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $projects = array_values(array_filter(
            (array) ($payload['projects'] ?? []),
            fn ($project): bool => is_array($project)
        ));

        return [
            'schema' => 'plos_whiteroom_monitor_compact.v1',
            'compact' => true,
            'mode' => 'observe',
            'read_only' => true,
            'as_of' => (string) ($payload['as_of'] ?? ''),
            'reviewed_at' => (string) ($payload['reviewed_at'] ?? ''),
            'monitor_reviewed_at' => (string) ($payload['monitor_reviewed_at'] ?? ''),
            'research_reviewed_at' => (string) ($payload['research_reviewed_at'] ?? ''),
            'filtered_total' => (int) ($payload['filtered_total'] ?? count($projects)),
            'omitted_by_limit' => (int) ($payload['omitted_by_limit'] ?? 0),
            'summary' => $payload['summary'] ?? [],
            'watch_refs' => array_map(function (array $project): array {
                return [
                    'project_key' => (string) ($project['key'] ?? 'unknown'),
                    'registry' => (string) ($project['registry'] ?? 'unknown'),
                    'source_type' => (string) ($project['source_type'] ?? 'unknown'),
                    'identifier_scheme' => (string) ($project['identifier_scheme'] ?? 'none'),
                    'source_version' => (string) ($project['source_version'] ?? 'unknown'),
                    'source_updated_at' => $project['source_updated_at'] ?? null,
                    'priority' => (string) ($project['priority'] ?? 'unknown'),
                    'area' => (string) ($project['area'] ?? 'unknown'),
                    'status' => (string) ($project['status'] ?? 'unknown'),
                    'due' => (bool) ($project['due'] ?? false),
                    'cadence_days' => (int) ($project['monitor_cadence_days'] ?? 0),
                    'days_since_review' => $project['days_since_review'] ?? null,
                    'next_review_after' => $project['next_review_after'] ?? null,
                ];
            }, $projects),
            'posture' => [
                'adoption_policy' => 'lawful_direct_reuse_preferred',
                'license_or_other_legal_basis_required' => true,
                'source_reuse_allowed_when_lawful' => true,
                'dependency_change_approved' => false,
                'routing_change_approved' => false,
                'schema_change_approved' => false,
                'unattended_browser_action_approved' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function filteredProjects(WhiteroomProjectRegistryService $registry, string $asOf): array
    {
        $areas = $this->normalizedOptionSet('area');
        $statuses = $this->normalizedOptionSet('status');
        $priorities = $this->normalizedOptionSet('priority');
        $registries = $this->normalizedOptionSet('registry');
        $sourceTypes = $this->normalizedOptionSet('source-type');
        $dueOnly = (bool) $this->option('due');

        return array_values(array_filter(array_map(
            fn (array $project): array => $registry->withReviewCadence($project, $asOf),
            $registry->all()
        ), function (array $project) use ($areas, $statuses, $priorities, $registries, $sourceTypes, $dueOnly): bool {
            if ($areas !== [] && ! in_array(strtolower((string) ($project['area'] ?? '')), $areas, true)) {
                return false;
            }

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

            if ($dueOnly && ! (bool) ($project['due'] ?? false)) {
                return false;
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
     */
    private function sortProjects(array &$projects): void
    {
        usort($projects, function (array $left, array $right): int {
            return [
                (bool) ($left['due'] ?? false) ? 0 : 1,
                $this->priorityWeight((string) ($left['priority'] ?? 'unknown')),
                strtolower((string) ($left['area'] ?? '')),
                strtolower((string) ($left['key'] ?? '')),
            ] <=> [
                (bool) ($right['due'] ?? false) ? 0 : 1,
                $this->priorityWeight((string) ($right['priority'] ?? 'unknown')),
                strtolower((string) ($right['area'] ?? '')),
                strtolower((string) ($right['key'] ?? '')),
            ];
        });
    }

    private function priorityWeight(string $priority): int
    {
        return match (strtolower($priority)) {
            'p0' => 0,
            'p1' => 1,
            'p2' => 2,
            'reference' => 3,
            default => 4,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<string, mixed>
     */
    private function summary(array $projects): array
    {
        $byArea = [];
        $byStatus = [];
        $byPriority = [];
        $byRegistry = [];
        $bySourceType = [];
        $due = 0;

        foreach ($projects as $project) {
            $area = (string) ($project['area'] ?? 'unknown');
            $status = (string) ($project['status'] ?? 'unknown');
            $priority = (string) ($project['priority'] ?? 'unknown');
            $registry = (string) ($project['registry'] ?? 'unknown');
            $sourceType = (string) ($project['source_type'] ?? 'unknown');

            $byArea[$area] = ($byArea[$area] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $byPriority[$priority] = ($byPriority[$priority] ?? 0) + 1;
            $byRegistry[$registry] = ($byRegistry[$registry] ?? 0) + 1;
            $bySourceType[$sourceType] = ($bySourceType[$sourceType] ?? 0) + 1;
            $due += (bool) ($project['due'] ?? false) ? 1 : 0;
        }

        ksort($byArea);
        ksort($byStatus);
        ksort($byPriority);
        ksort($byRegistry);
        ksort($bySourceType);

        return [
            'total' => count($projects),
            'due' => $due,
            'by_area' => $byArea,
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
        $this->info('Upstream adoption monitor');
        $this->line('  As of: '.($payload['as_of'] ?? 'unknown'));
        $this->line('  Monitor refreshed: '.($payload['monitor_reviewed_at'] ?? 'unknown'));
        $this->line('  Research refreshed: '.($payload['research_reviewed_at'] ?? 'unknown'));
        $this->line('  Sources: '.($payload['summary']['total'] ?? 0));
        $this->line('  Due: '.($payload['summary']['due'] ?? 0));
        $this->newLine();

        foreach ($payload['projects'] as $project) {
            $this->line(sprintf(
                '[%s] %s - %s%s',
                $project['priority'] ?? 'unknown',
                $project['name'] ?? $project['key'] ?? 'unknown',
                $project['area'] ?? 'unknown',
                ($project['due'] ?? false) ? ' (due)' : ''
            ));
            $this->line('  Source: '.($project['source_url'] ?? $project['repo'] ?? ''));
            if (! empty($project['identifier'])) {
                $this->line('  Identifier: '.($project['identifier_scheme'] ?? 'unknown').':'.($project['identifier'] ?? ''));
            }
            $this->line('  Status: '.($project['status'] ?? 'unknown').' / '.($project['registry'] ?? 'unknown'));
            $this->line('  Cadence: every '.($project['monitor_cadence_days'] ?? '?').' days; last '.($project['last_reviewed_at'] ?? 'unknown').'; next '.($project['next_review_after'] ?? 'unknown'));

            $signals = array_values(array_filter((array) ($project['breakthrough_signals'] ?? [])));
            if ($signals !== []) {
                $this->line('  Signals: '.implode('; ', $signals));
            }

            $this->line('  Next: '.($project['next_action'] ?? ''));
        }
    }
}
