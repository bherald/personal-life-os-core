<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WhiteroomProjectRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_merge(
            $this->curatedProjects(),
            $this->monitorProjects(),
            $this->researchSources()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function curatedProjects(): array
    {
        $projects = config('whiteroom_projects.projects', []);
        if (! is_array($projects)) {
            return [];
        }

        return array_values(array_map(function (array $project): array {
            return array_merge([
                'registry' => 'curated_whiteroom',
                'source_type' => 'repository',
                'source_url' => (string) ($project['repo'] ?? ''),
                'area' => (string) ($project['fit'] ?? 'curated_whiteroom'),
                'last_reviewed_at' => config('whiteroom_projects.reviewed_at'),
                'monitor_cadence_days' => 30,
                'breakthrough_signals' => [],
            ], $project);
        }, $projects));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monitorProjects(): array
    {
        $projects = config('whiteroom_projects.monitor_projects', []);
        if (! is_array($projects)) {
            return [];
        }

        $areas = config('whiteroom_projects.monitor_areas', []);
        $areas = is_array($areas) ? $areas : [];

        return array_values(array_map(function (array $project) use ($areas): array {
            $areaKey = (string) ($project['area'] ?? 'uncategorized');
            $area = $areas[$areaKey] ?? [];
            $area = is_array($area) ? $area : [];

            return array_merge([
                'registry' => 'monitor_watchlist',
                'source_type' => 'repository',
                'source_url' => (string) ($project['repo'] ?? ''),
                'license' => 'monitor; verify before adoption',
                'status' => (string) ($area['default_status'] ?? 'watch_only'),
                'fit' => (string) ($area['label'] ?? $areaKey),
                'sidecar_policy' => (string) ($area['sidecar_policy'] ?? 'license_review_then_adopt_or_watch'),
                'plos_surface' => (string) ($area['plos_surface'] ?? ''),
                'monitor_cadence_days' => (int) ($area['default_cadence_days'] ?? 30),
                'last_reviewed_at' => config('whiteroom_projects.monitor_reviewed_at', config('whiteroom_projects.reviewed_at')),
                'breakthrough_signals' => (array) ($area['breakthrough_signals'] ?? []),
            ], $project);
        }, $projects));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function researchSources(): array
    {
        $sources = config('whiteroom_projects.research_sources', []);
        if (! is_array($sources)) {
            return [];
        }

        $areas = config('whiteroom_projects.monitor_areas', []);
        $areas = is_array($areas) ? $areas : [];

        return array_values(array_map(function (array $source) use ($areas): array {
            $areaKey = (string) ($source['area'] ?? 'research_metadata');
            $area = $areas[$areaKey] ?? [];
            $area = is_array($area) ? $area : [];

            return array_merge([
                'registry' => 'research_watchlist',
                'source_type' => 'paper',
                'license' => 'Reference and citation only; verify source terms before copying protected expression',
                'legal_basis' => 'research_reference_and_factual_bibliographic_metadata',
                'status' => 'research_reference',
                'fit' => (string) ($area['label'] ?? $areaKey),
                'sidecar_policy' => 'research_reference_no_runtime_dependency',
                'plos_surface' => (string) ($area['plos_surface'] ?? ''),
                'monitor_cadence_days' => (int) ($area['default_cadence_days'] ?? 30),
                'last_reviewed_at' => config('whiteroom_projects.research_reviewed_at', config('whiteroom_projects.monitor_reviewed_at')),
                'breakthrough_signals' => (array) ($area['breakthrough_signals'] ?? []),
            ], $source);
        }, $sources));
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    public function withReviewCadence(array $project, ?string $asOf = null): array
    {
        $reference = CarbonImmutable::parse($asOf ?: CarbonImmutable::now()->toDateString())->startOfDay();
        $lastReviewedAt = $project['last_reviewed_at'] ?? null;
        $cadenceDays = max(1, (int) ($project['monitor_cadence_days'] ?? 30));
        $daysSinceReview = null;

        if (is_string($lastReviewedAt) && trim($lastReviewedAt) !== '') {
            $lastReviewed = CarbonImmutable::parse($lastReviewedAt)->startOfDay();
            $daysSinceReview = max(0, $lastReviewed->diffInDays($reference, false));
        }

        $project['monitor_cadence_days'] = $cadenceDays;
        $project['days_since_review'] = $daysSinceReview;
        $project['due'] = $daysSinceReview === null || $daysSinceReview >= $cadenceDays;
        $project['next_review_after'] = $daysSinceReview === null
            ? null
            : CarbonImmutable::parse((string) $lastReviewedAt)->startOfDay()->addDays($cadenceDays)->toDateString();

        return $project;
    }
}
