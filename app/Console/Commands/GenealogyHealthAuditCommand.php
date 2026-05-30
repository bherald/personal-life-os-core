<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyHealthAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyHealthAuditCommand extends Command
{
    protected $signature = 'genealogy:health-audit
        {--tree=4 : Tree ID to inspect}
        {--all-trees : Audit every known genealogy tree}
        {--root= : Expected self-contained FT media root; defaults to tree-inferred media root}
        {--limit=50 : Max sample rows per issue}
        {--sections= : Comma-separated sections: links,dates,media,rag,citations,duplicates,export}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Omit path/sample details for dashboards and MCP}
        {--aggregate : With --json, emit aggregate-only output without tree, issue, entity, sample, or path details}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only Genea health audit for tree links, dates, media, RAG, citations, duplicates, and export readiness';

    public function handle(GenealogyHealthAuditService $audit): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        if ($this->option('aggregate') && ! $this->option('json')) {
            $this->error('The --aggregate option is only supported with --json.');

            return self::FAILURE;
        }

        $sections = $this->option('sections')
            ? explode(',', (string) $this->option('sections'))
            : [];

        if ($this->option('all-trees')) {
            return $this->handleAllTrees($audit, $sections);
        }

        $payload = $audit->collect(
            treeId: (int) $this->option('tree'),
            root: $this->option('root') ? (string) $this->option('root') : null,
            limit: (int) $this->option('limit'),
            sections: $sections,
            dryRun: (bool) $this->option('dry-run'),
        );

        if ($this->option('compact') || $this->option('aggregate')) {
            $payload = $audit->compactPayload($payload);
        }

        if ($this->option('aggregate')) {
            $payload = $this->aggregateOnlyPayload($payload, allTrees: false);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy health audit JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($audit->toMarkdown($payload));

            return self::SUCCESS;
        }

        $this->line($audit->toText($payload));

        return self::SUCCESS;
    }

    private function handleAllTrees(GenealogyHealthAuditService $audit, array $sections): int
    {
        $treeIds = $this->knownTreeIds();
        if ($treeIds === []) {
            $treeIds = [(int) $this->option('tree')];
        }

        $trees = [];
        foreach ($treeIds as $treeId) {
            $payload = $audit->collect(
                treeId: $treeId,
                root: null,
                limit: (int) $this->option('limit'),
                sections: $sections,
                dryRun: (bool) $this->option('dry-run'),
            );

            $trees[] = ($this->option('compact') || $this->option('aggregate'))
                ? $audit->compactPayload($payload)
                : $payload;
        }

        $payload = [
            'version' => 1,
            'command' => 'genealogy:health-audit',
            'mode' => 'observe',
            'all_trees' => true,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'tree_count' => count($trees),
            'status' => 'observe_ok',
            'summary' => $this->aggregateSummary($trees),
            'trees' => $trees,
        ];

        if ($this->option('aggregate')) {
            $payload = $this->aggregateOnlyPayload($payload, allTrees: true);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line('# Genealogy Health Audit');
            $this->newLine();
            foreach ($trees as $tree) {
                $this->line(sprintf(
                    '- Tree `%s`: %d issue categories, %d affected rows',
                    $tree['tree_id'] ?? 'unknown',
                    $tree['summary']['issue_count'] ?? 0,
                    $tree['summary']['issue_rows'] ?? 0
                ));
            }

            return self::SUCCESS;
        }

        $this->line('Genealogy health audit: all trees');
        foreach ($trees as $tree) {
            $this->line(sprintf(
                '  - Tree %s: %d issue categories, %d affected rows',
                $tree['tree_id'] ?? 'unknown',
                $tree['summary']['issue_count'] ?? 0,
                $tree['summary']['issue_rows'] ?? 0
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function knownTreeIds(): array
    {
        if (Schema::hasTable('genealogy_trees') && Schema::hasColumn('genealogy_trees', 'id')) {
            return DB::table('genealogy_trees')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        if (Schema::hasTable('genealogy_persons') && Schema::hasColumn('genealogy_persons', 'tree_id')) {
            return DB::table('genealogy_persons')
                ->select('tree_id')
                ->distinct()
                ->orderBy('tree_id')
                ->pluck('tree_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        return [];
    }

    private function aggregateSummary(array $trees): array
    {
        $summary = [
            'persons' => 0,
            'families' => 0,
            'children' => 0,
            'media' => 0,
            'sources' => 0,
            'issue_count' => 0,
            'issue_rows' => 0,
        ];

        foreach ($trees as $tree) {
            foreach (array_keys($summary) as $key) {
                $summary[$key] += (int) ($tree['summary'][$key] ?? 0);
            }
        }

        return $summary;
    }

    private function aggregateOnlyPayload(array $payload, bool $allTrees): array
    {
        $trees = $allTrees
            ? array_values(array_filter($payload['trees'] ?? [], 'is_array'))
            : [$payload];
        $issueDimensions = $this->aggregateIssueDimensions($trees);
        $summary = $allTrees
            ? ($payload['summary'] ?? $this->aggregateSummary($trees))
            : ($payload['summary'] ?? []);

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'command' => 'genealogy:health-audit',
            'mode' => 'observe',
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => (string) ($payload['captured_at'] ?? now()->toIso8601String()),
            'all_trees' => $allTrees,
            'tree_count' => $allTrees ? (int) ($payload['tree_count'] ?? count($trees)) : 1,
            'compact' => true,
            'aggregate' => true,
            'status' => (string) ($payload['status'] ?? 'observe_ok'),
            'sections' => $this->aggregateSections($trees),
            'limit' => (int) ($payload['limit'] ?? $this->option('limit')),
            'summary' => $summary,
            'issue_code_counts' => $issueDimensions['codes'],
            'severity_counts' => $issueDimensions['severities'],
            'section_counts' => $issueDimensions['sections'],
            'confidence_counts' => $issueDimensions['confidences'],
            'safe_auto_fix_categories' => $issueDimensions['safe_auto_fix_categories'],
            'review_required_categories' => $issueDimensions['review_required_categories'],
            'posture' => [
                'aggregate_only' => true,
                'tree_details_included' => false,
                'issue_details_included' => false,
                'entity_details_included' => false,
                'review_targets_included' => false,
                'samples_included' => false,
                'paths_included' => false,
                'writes_allowed' => false,
                'scheduled_output_safe' => true,
            ],
        ];
    }

    private function aggregateIssueDimensions(array $trees): array
    {
        $codes = [];
        $severities = [];
        $sections = [];
        $confidences = [];
        $safeAutoFixCategories = 0;
        $reviewRequiredCategories = 0;

        foreach ($trees as $tree) {
            foreach (($tree['issues'] ?? []) as $issue) {
                if (! is_array($issue)) {
                    continue;
                }

                $code = $this->aggregateKey($issue['code'] ?? null, 'unknown_issue');
                $severity = $this->aggregateKey($issue['severity'] ?? null, 'unknown');
                $section = $this->aggregateKey($issue['section'] ?? null, 'unknown');
                $confidence = $this->aggregateKey($issue['confidence'] ?? null, 'unknown');
                $affectedRows = (int) ($issue['count'] ?? 0);

                $codes[$code] ??= ['issue_categories' => 0, 'affected_rows' => 0];
                $codes[$code]['issue_categories']++;
                $codes[$code]['affected_rows'] += $affectedRows;
                $severities[$severity] = ($severities[$severity] ?? 0) + 1;
                $sections[$section] = ($sections[$section] ?? 0) + 1;
                $confidences[$confidence] = ($confidences[$confidence] ?? 0) + 1;

                if ((bool) ($issue['safe_auto_fix'] ?? false)) {
                    $safeAutoFixCategories++;
                } else {
                    $reviewRequiredCategories++;
                }
            }
        }

        ksort($codes);
        ksort($severities);
        ksort($sections);
        ksort($confidences);

        return [
            'codes' => $codes,
            'severities' => $severities,
            'sections' => $sections,
            'confidences' => $confidences,
            'safe_auto_fix_categories' => $safeAutoFixCategories,
            'review_required_categories' => $reviewRequiredCategories,
        ];
    }

    private function aggregateSections(array $trees): array
    {
        $sections = [];

        foreach ($trees as $tree) {
            foreach (($tree['sections'] ?? []) as $section) {
                if (is_scalar($section) && (string) $section !== '') {
                    $sections[] = (string) $section;
                }
            }
        }

        $sections = array_values(array_unique($sections));
        sort($sections);

        return $sections;
    }

    private function aggregateKey(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }
}
