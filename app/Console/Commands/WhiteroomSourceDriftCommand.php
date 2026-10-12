<?php

namespace App\Console\Commands;

use App\Services\UpstreamSourceDriftRuntimePolicyService;
use App\Services\UpstreamSourceDriftService;
use App\Services\WhiteroomProjectRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class WhiteroomSourceDriftCommand extends Command
{
    protected $signature = 'whiteroom:source-drift
        {--format=human : Output format: human or json}
        {--compact : With --format=json, emit content-free aggregate output}
        {--project=* : Restrict to one or more registry project keys}
        {--area=* : Restrict to one or more monitor areas}
        {--registry=* : Restrict to one or more registry sources}
        {--source-type=* : Restrict to repository or paper sources}
        {--priority=* : Restrict to one or more priorities}
        {--limit= : Maximum supported sources to probe}
        {--timeout= : Per-request timeout in seconds}
        {--fail-on-drift : Return failure when a recorded source has changed}
        {--require-complete : Return failure when requested projects are unknown/untrackable/filtered, eligible sources are omitted, or metadata checks are inconclusive}';

    protected $description = 'Compare recorded repository commits and arXiv versions with bounded official metadata APIs';

    protected $aliases = ['upstream:source-drift'];

    public function handle(
        WhiteroomProjectRegistryService $registry,
        UpstreamSourceDriftService $driftService,
        UpstreamSourceDriftRuntimePolicyService $runtimePolicy,
    ): int {
        $format = strtolower(trim((string) $this->option('format')));
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('Invalid --format. Use human or json.');

            return self::FAILURE;
        }
        if ((bool) $this->option('compact') && $format !== 'json') {
            $this->error('The --compact option is only supported with --format=json.');

            return self::FAILURE;
        }

        try {
            $policy = $runtimePolicy->required();
            $limit = $runtimePolicy->resolveOption(
                $this->option('limit'),
                'limit',
                $policy['default_limit'],
                $policy['max_limit'],
            );
            $timeout = $runtimePolicy->resolveOption(
                $this->option('timeout'),
                'timeout',
                $policy['request_timeout_seconds'],
                $policy['max_request_timeout_seconds'],
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $selection = $this->sourceSelection($registry);
        $eligibleSources = $selection['trackable_sources'];
        $sources = array_slice($eligibleSources, 0, $limit);
        $results = $driftService->inspect(
            $sources,
            $policy['connect_timeout_seconds'],
            $timeout
        );
        $summary = $this->summary($results);
        $coverage = $this->coverage(
            eligibleTotal: count($eligibleSources),
            selectedTotal: count($sources),
            checkedTotal: count($results),
            summary: $summary,
            projectRequest: $selection['project_request'],
        );
        $payload = [
            'schema' => 'plos_upstream_source_drift.v2',
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'mode' => 'observe',
            'read_only' => true,
            'selection' => [
                'eligible_total' => count($eligibleSources),
                'checked_total' => count($results),
                'limit' => $limit,
                'project_request' => $selection['project_request'],
            ],
            'summary' => $summary,
            'coverage' => $coverage,
            'results' => $results,
            'posture' => $this->posture(),
        ];

        if ((bool) $this->option('compact')) {
            $payload = $this->compactPayload($payload);
        }

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($payload);
        }

        $driftFailed = (bool) $this->option('fail-on-drift') && $summary['drift'] > 0;
        $coverageFailed = (bool) $this->option('require-complete') && ! $coverage['complete'];

        return $driftFailed || $coverageFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{
     *     trackable_sources:array<int, array<string, mixed>>,
     *     project_request:array<string, mixed>
     * }
     */
    private function sourceSelection(WhiteroomProjectRegistryService $registry): array
    {
        $projects = $this->normalizedOptionSet('project');
        $areas = $this->normalizedOptionSet('area');
        $registries = $this->normalizedOptionSet('registry');
        $sourceTypes = $this->normalizedOptionSet('source-type');
        $priorities = $this->normalizedOptionSet('priority');

        $allSources = $registry->all();
        $matchedSources = array_values(array_filter($allSources, function (array $source) use ($projects, $areas, $registries, $sourceTypes, $priorities): bool {
            return ($projects === [] || in_array(strtolower((string) ($source['key'] ?? '')), $projects, true))
                && ($areas === [] || in_array(strtolower((string) ($source['area'] ?? '')), $areas, true))
                && ($registries === [] || in_array(strtolower((string) ($source['registry'] ?? '')), $registries, true))
                && ($sourceTypes === [] || in_array(strtolower((string) ($source['source_type'] ?? '')), $sourceTypes, true))
                && ($priorities === [] || in_array(strtolower((string) ($source['priority'] ?? '')), $priorities, true));
        }));

        $sources = array_values(array_filter($matchedSources, fn (array $source): bool => in_array(
            strtolower((string) ($source['identifier_scheme'] ?? '')),
            ['git_commit', 'arxiv'],
            true
        )));

        usort($sources, fn (array $left, array $right): int => [
            $this->priorityWeight((string) ($left['priority'] ?? 'unknown')),
            strtolower((string) ($left['key'] ?? '')),
        ] <=> [
            $this->priorityWeight((string) ($right['priority'] ?? 'unknown')),
            strtolower((string) ($right['key'] ?? '')),
        ]);

        $knownKeys = $this->sourceKeys($allSources);
        $matchedKeys = $this->sourceKeys($matchedSources);
        $trackableKeys = $this->sourceKeys($sources);
        $unknownKeys = $projects === [] ? [] : array_values(array_diff($projects, $knownKeys));
        $filteredOutKeys = $projects === [] ? [] : array_values(array_diff($projects, $unknownKeys, $matchedKeys));
        $untrackableKeys = $projects === [] ? [] : array_values(array_diff($matchedKeys, $trackableKeys));
        sort($unknownKeys);
        sort($filteredOutKeys);
        sort($untrackableKeys);

        return [
            'trackable_sources' => $sources,
            'project_request' => [
                'explicit' => $projects !== [],
                'requested_total' => count($projects),
                'matched_total' => count(array_intersect($projects, $matchedKeys)),
                'trackable_total' => count(array_intersect($projects, $trackableKeys)),
                'untrackable_total' => count($untrackableKeys),
                'unknown_total' => count($unknownKeys),
                'filtered_out_total' => count($filteredOutKeys),
                'untrackable_refs' => $untrackableKeys,
                'unknown_refs' => $unknownKeys,
                'filtered_out_refs' => $filteredOutKeys,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, string>
     */
    private function sourceKeys(array $sources): array
    {
        $keys = array_values(array_unique(array_filter(array_map(
            fn (array $source): string => strtolower(trim((string) ($source['key'] ?? ''))),
            $sources
        ))));
        sort($keys);

        return $keys;
    }

    /** @return array<int, string> */
    private function normalizedOptionSet(string $name): array
    {
        $values = $this->option($name);
        $values = is_array($values) ? $values : [$values];

        return array_values(array_unique(array_filter(array_map(
            fn ($value): string => strtolower(trim((string) $value)),
            $values
        ))));
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
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, int>
     */
    private function summary(array $results): array
    {
        $summary = [
            'total' => count($results),
            'current' => 0,
            'drift' => 0,
            'unavailable' => 0,
            'rate_limited' => 0,
            'other' => 0,
        ];

        foreach ($results as $result) {
            $state = (string) ($result['state'] ?? 'other');
            if (array_key_exists($state, $summary)) {
                $summary[$state]++;
            } elseif ($state === 'skipped_rate_limited') {
                $summary['rate_limited']++;
            } else {
                $summary['other']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, int>  $summary
     * @return array<string, mixed>
     */
    private function coverage(
        int $eligibleTotal,
        int $selectedTotal,
        int $checkedTotal,
        array $summary,
        array $projectRequest
    ): array {
        $omittedByLimit = max(0, $eligibleTotal - $selectedTotal);
        $missingResults = max(0, $selectedTotal - $checkedTotal);
        $reasonCodes = [];

        if ($omittedByLimit > 0) {
            $reasonCodes[] = 'source_limit_reached';
        }
        if ($missingResults > 0) {
            $reasonCodes[] = 'source_results_missing';
        }
        if (($summary['unavailable'] ?? 0) > 0) {
            $reasonCodes[] = 'source_unavailable';
        }
        if (($summary['rate_limited'] ?? 0) > 0) {
            $reasonCodes[] = 'source_rate_limited';
        }
        if (($summary['other'] ?? 0) > 0) {
            $reasonCodes[] = 'source_state_unclassified';
        }
        if (($projectRequest['untrackable_total'] ?? 0) > 0) {
            $reasonCodes[] = 'requested_project_untrackable';
        }
        if (($projectRequest['unknown_total'] ?? 0) > 0) {
            $reasonCodes[] = 'requested_project_unknown';
        }
        if (($projectRequest['filtered_out_total'] ?? 0) > 0) {
            $reasonCodes[] = 'requested_project_filtered_out';
        }

        return [
            'schema' => 'plos_upstream_source_drift_coverage.v2',
            'status' => $reasonCodes === [] ? 'complete' : 'incomplete',
            'complete' => $reasonCodes === [],
            'eligible_total' => $eligibleTotal,
            'selected_total' => $selectedTotal,
            'checked_total' => $checkedTotal,
            'omitted_by_limit' => $omittedByLimit,
            'missing_results' => $missingResults,
            'inconclusive_results' => (int) ($summary['unavailable'] ?? 0)
                + (int) ($summary['rate_limited'] ?? 0)
                + (int) ($summary['other'] ?? 0),
            'reason_codes' => $reasonCodes,
        ];
    }

    /** @return array<string, bool|string> */
    private function posture(): array
    {
        return [
            'metadata_only' => true,
            'source_text_retained' => false,
            'writes_config' => false,
            'writes_database' => false,
            'approves_adoption' => false,
            'changes_runtime' => false,
            'session_token_persisted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $results = (array) ($payload['results'] ?? []);

        return [
            'schema' => 'plos_upstream_source_drift_compact.v2',
            'checked_at' => $payload['checked_at'],
            'mode' => 'observe',
            'read_only' => true,
            'selection' => $payload['selection'],
            'summary' => $payload['summary'],
            'coverage' => $payload['coverage'],
            'drift_refs' => array_values(array_map(
                fn (array $result): array => [
                    'project_key' => $result['project_key'],
                    'source_type' => $result['source_type'],
                    'identifier_scheme' => $result['identifier_scheme'],
                    'recorded_version' => $result['recorded_version'] ?? null,
                    'observed_version' => $result['observed_version'] ?? null,
                    'observed_updated_at' => $result['observed_updated_at'] ?? null,
                    'drift_fields' => $result['drift_fields'] ?? [],
                ],
                array_filter($results, fn (array $result): bool => ($result['state'] ?? null) === 'drift')
            )),
            'unavailable_refs' => array_values(array_map(
                fn (array $result): array => [
                    'project_key' => $result['project_key'],
                    'state' => $result['state'],
                    'reason_code' => $result['reason_code'],
                ],
                array_filter($results, fn (array $result): bool => ! in_array($result['state'] ?? null, ['current', 'drift'], true))
            )),
            'posture' => $payload['posture'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function renderHuman(array $payload): void
    {
        $this->info('Upstream source drift');
        $this->line('  Checked: '.($payload['summary']['total'] ?? 0));
        $this->line('  Current: '.($payload['summary']['current'] ?? 0));
        $this->line('  Drift: '.($payload['summary']['drift'] ?? 0));
        $this->line('  Unavailable: '.($payload['summary']['unavailable'] ?? 0));
        $this->line(sprintf(
            '  Coverage: %s (%d/%d checked, %d omitted, %d inconclusive)',
            $payload['coverage']['status'] ?? 'unknown',
            $payload['coverage']['checked_total'] ?? 0,
            $payload['coverage']['eligible_total'] ?? 0,
            $payload['coverage']['omitted_by_limit'] ?? 0,
            $payload['coverage']['inconclusive_results'] ?? 0
        ));
        if (($payload['coverage']['reason_codes'] ?? []) !== []) {
            $this->line('  Coverage reasons: '.implode(', ', $payload['coverage']['reason_codes']));
        }
        if (($payload['selection']['project_request']['explicit'] ?? false) === true) {
            $request = $payload['selection']['project_request'];
            $this->line(sprintf(
                '  Requested projects: %d requested, %d matched, %d trackable, %d untrackable, %d unknown, %d filtered out',
                $request['requested_total'] ?? 0,
                $request['matched_total'] ?? 0,
                $request['trackable_total'] ?? 0,
                $request['untrackable_total'] ?? 0,
                $request['unknown_total'] ?? 0,
                $request['filtered_out_total'] ?? 0
            ));
        }

        foreach ((array) ($payload['results'] ?? []) as $result) {
            $this->line(sprintf(
                '[%s] %s: %s -> %s',
                $result['state'] ?? 'unknown',
                $result['project_key'] ?? 'unknown',
                $result['recorded_version'] ?? 'unknown',
                $result['observed_version'] ?? 'unknown'
            ));
        }
    }
}
