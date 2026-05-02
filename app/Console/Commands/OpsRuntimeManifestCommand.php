<?php

namespace App\Console\Commands;

use App\Services\SkillLoaderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runtime Manifest and Drift Report (B3).
 *
 * Read-only. Inventories skills, scheduled jobs, runtime prompts, and known
 * runtime seams; flags rows missing typed metadata; emits a drift summary.
 *
 * Exits 0 even when drift is present — this is an advisory surface, not a
 * gate. Runs safely on dev or prod.
 */
class OpsRuntimeManifestCommand extends Command
{
    protected $signature = 'ops:runtime-manifest
                            {--json : Output JSON envelope instead of pretty text}
                            {--section=all : all|skills|jobs|prompts|seams|drift}';

    protected $description = 'Inventory runtime surfaces and report typed-metadata drift (read-only).';

    private const PROMPT_INVENTORY_PATH = 'docs/plos-prompt-asset-inventory.md';

    private const REQUIRED_SKILL_METADATA = ['runtime_role', 'write_scope', 'parallel_mode', 'review_mode'];

    private const REQUIRED_JOB_METADATA = ['runtime_mode', 'workload_family', 'resource_profile', 'stall_policy', 'backlog_metric', 'notification_mode'];

    public function handle(): int
    {
        $section = (string) $this->option('section');
        $valid = ['all', 'skills', 'jobs', 'prompts', 'seams', 'drift'];
        if (! in_array($section, $valid, true)) {
            $this->error(sprintf('Section must be one of: %s', implode(', ', $valid)));

            return self::FAILURE;
        }

        $envelope = $this->buildEnvelope($section);

        if ($this->option('json')) {
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderPretty($envelope);
        }

        return self::SUCCESS;
    }

    private function buildEnvelope(string $section): array
    {
        // Always compute all sections internally so drift can aggregate; filter
        // the output by the requested section afterward.
        $full = [
            'skills' => $this->buildSkillsSection(),
            'jobs' => $this->buildJobsSection(),
            'prompts' => $this->buildPromptsSection(),
            'seams' => $this->buildSeamsSection(),
        ];
        $full['drift'] = $this->buildDriftSummary($full);

        $result = $section === 'all' ? $full : [$section => $full[$section]];

        return [
            'version' => 1,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'section' => $section,
            'result' => $result,
        ];
    }

    private function buildSkillsSection(): array
    {
        $loader = app(SkillLoaderService::class);
        $skills = $loader->getSkillIndex();

        $rows = [];
        $missingCount = 0;
        foreach ($skills as $skill) {
            $missing = [];
            foreach (self::REQUIRED_SKILL_METADATA as $field) {
                $value = $skill[$field] ?? null;
                if (! is_string($value) || $value === '') {
                    $missing[] = $field;
                }
            }

            if ($missing !== []) {
                $missingCount++;
            }

            $rows[] = [
                'name' => $skill['name'] ?? null,
                'runtime_role' => $skill['runtime_role'] ?? null,
                'write_scope' => $skill['write_scope'] ?? null,
                'parallel_mode' => $skill['parallel_mode'] ?? null,
                'review_mode' => $skill['review_mode'] ?? null,
                'missing_metadata' => $missing,
            ];
        }

        return [
            'total' => count($rows),
            'missing_metadata_count' => $missingCount,
            'protected_write_scopes' => SkillLoaderService::PROTECTED_WRITE_SCOPES,
            'declared_write_scopes' => $loader->listDeclaredWriteScopes(),
            'rows' => $rows,
        ];
    }

    private function buildJobsSection(): array
    {
        try {
            $jobRows = DB::select(
                "SELECT id, name, enabled, job_type, runtime_mode, workload_family,
                        resource_profile, stall_policy, backlog_metric, notification_mode,
                        last_run_status, last_run_at
                 FROM scheduled_jobs
                 ORDER BY name ASC"
            );
        } catch (\Throwable $e) {
            return [
                'result' => 'query_failed',
                'error' => $e->getMessage(),
            ];
        }

        $rows = [];
        $missingCount = 0;
        foreach ($jobRows as $row) {
            $missing = [];
            foreach (self::REQUIRED_JOB_METADATA as $field) {
                $value = $row->{$field} ?? null;
                if ($value === null || $value === '') {
                    $missing[] = $field;
                }
            }

            if ($missing !== []) {
                $missingCount++;
            }

            $rows[] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'enabled' => (bool) $row->enabled,
                'job_type' => $row->job_type,
                'runtime_mode' => $row->runtime_mode,
                'workload_family' => $row->workload_family,
                'resource_profile' => $row->resource_profile,
                'stall_policy' => $row->stall_policy,
                'backlog_metric' => $row->backlog_metric,
                'notification_mode' => $row->notification_mode,
                'missing_metadata' => $missing,
            ];
        }

        return [
            'total' => count($rows),
            'missing_metadata_count' => $missingCount,
            'rows' => $rows,
        ];
    }

    private function buildPromptsSection(): array
    {
        $path = base_path(self::PROMPT_INVENTORY_PATH);
        if (! file_exists($path)) {
            return [
                'result' => 'inventory_missing',
                'path' => self::PROMPT_INVENTORY_PATH,
            ];
        }

        $content = file_get_contents($path);
        $inventoryMtime = filemtime($path);

        $rows = [];
        $missingSourceCount = 0;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || ! str_starts_with($trimmed, '|')) {
                continue;
            }

            // Split on pipes and remove Markdown inline-code markers from cells.
            $parts = array_map(
                static fn (string $c) => trim(str_replace('`', '', $c)),
                array_slice(explode('|', $trimmed), 1, -1)
            );

            // Data rows have at least 5 columns: idx, asset, owner, source, category.
            if (count($parts) < 5) {
                continue;
            }

            // Skip header and separator rows.
            if (! ctype_digit($parts[0])) {
                continue;
            }

            $idx = (int) $parts[0];
            $asset = $parts[1];
            $owner = $parts[2];
            $sourceRef = $parts[3];
            $category = strtolower($parts[4]);

            // Normalize source by stripping any whitespace the split left behind.
            $sourceRef = trim($sourceRef);

            $fileRef = explode(':', $sourceRef, 2);
            $filePath = $fileRef[0] ?? '';
            $exists = $filePath !== '' && file_exists(base_path($filePath));
            $lastModified = $exists ? filemtime(base_path($filePath)) : null;

            if (! $exists) {
                $missingSourceCount++;
            }

            $rows[] = [
                'idx' => $idx,
                'asset' => $asset,
                'owner' => $owner,
                'source' => $sourceRef,
                'category' => $category,
                'source_exists' => $exists,
                'source_last_modified' => $lastModified ? date('Y-m-d\TH:i:s\Z', $lastModified) : null,
            ];
        }

        return [
            'inventory_path' => self::PROMPT_INVENTORY_PATH,
            'inventory_last_modified' => date('Y-m-d\TH:i:s\Z', $inventoryMtime),
            'total' => count($rows),
            'missing_source_count' => $missingSourceCount,
            'rows' => $rows,
        ];
    }

    private function buildSeamsSection(): array
    {
        // Source of truth: config/runtime_seams.php. Descriptive only —
        // no policy semantics live here. Ordering is load-bearing and
        // preserved as-read from config so drift between source and
        // manifest output is detectable.
        $seams = (array) config('runtime_seams.rows', []);

        $counts = ['real' => 0, 'mixed' => 0, 'placeholder-like' => 0];
        foreach ($seams as $seam) {
            $status = $seam['status'] ?? null;
            if ($status !== null && isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return [
            'total' => count($seams),
            'status_counts' => $counts,
            'rows' => $seams,
        ];
    }

    private function buildDriftSummary(array $sections): array
    {
        $summary = [];

        if (isset($sections['skills'])) {
            $skills = $sections['skills'];
            $summary['skills'] = [
                'total' => $skills['total'] ?? 0,
                'missing_metadata' => $skills['missing_metadata_count'] ?? 0,
                'worst_offender' => $this->worstMissingOffender($skills['rows'] ?? [], 'name'),
            ];
        }

        if (isset($sections['jobs'])) {
            $jobs = $sections['jobs'];
            $summary['jobs'] = [
                'total' => $jobs['total'] ?? 0,
                'missing_metadata' => $jobs['missing_metadata_count'] ?? 0,
                'worst_offender' => $this->worstMissingOffender($jobs['rows'] ?? [], 'name'),
            ];
        }

        if (isset($sections['prompts'])) {
            $prompts = $sections['prompts'];
            $summary['prompts'] = [
                'total' => $prompts['total'] ?? 0,
                'missing_source' => $prompts['missing_source_count'] ?? 0,
            ];
        }

        if (isset($sections['seams'])) {
            $seams = $sections['seams'];
            $summary['seams'] = [
                'total' => $seams['total'] ?? 0,
                'placeholder_like' => $seams['status_counts']['placeholder-like'] ?? 0,
            ];
        }

        return $summary;
    }

    private function worstMissingOffender(array $rows, string $nameKey): ?array
    {
        $worst = null;
        $worstCount = 0;
        foreach ($rows as $row) {
            $count = count($row['missing_metadata'] ?? []);
            if ($count > $worstCount) {
                $worst = ['name' => $row[$nameKey] ?? null, 'missing' => $row['missing_metadata']];
                $worstCount = $count;
            }
        }

        return $worst;
    }

    private function renderPretty(array $envelope): void
    {
        $result = $envelope['result'] ?? [];

        if (isset($result['skills'])) {
            $this->line(sprintf('Skills: %d total, %d with missing typed metadata',
                $result['skills']['total'] ?? 0,
                $result['skills']['missing_metadata_count'] ?? 0
            ));
        }

        if (isset($result['jobs'])) {
            $this->line(sprintf('Scheduled jobs: %d total, %d with missing typed metadata',
                $result['jobs']['total'] ?? 0,
                $result['jobs']['missing_metadata_count'] ?? 0
            ));
        }

        if (isset($result['prompts'])) {
            if (isset($result['prompts']['result']) && $result['prompts']['result'] === 'inventory_missing') {
                $this->warn('Prompt inventory file missing: '.$result['prompts']['path']);
            } else {
                $this->line(sprintf('Prompts: %d asset rows, %d with missing source file',
                    $result['prompts']['total'] ?? 0,
                    $result['prompts']['missing_source_count'] ?? 0
                ));
            }
        }

        if (isset($result['seams'])) {
            $counts = $result['seams']['status_counts'] ?? [];
            $this->line(sprintf('Runtime seams: %d total (real=%d mixed=%d placeholder-like=%d)',
                $result['seams']['total'] ?? 0,
                $counts['real'] ?? 0,
                $counts['mixed'] ?? 0,
                $counts['placeholder-like'] ?? 0
            ));
        }

        if (isset($result['drift'])) {
            $this->line('');
            $this->line('Drift summary:');
            foreach ($result['drift'] as $category => $summary) {
                $this->line(sprintf('  %s: %s', $category, json_encode($summary, JSON_UNESCAPED_SLASHES)));
            }
        }

        $this->line('');
        $this->line(sprintf('Captured at: %s UTC (section: %s)',
            $envelope['captured_at'] ?? '',
            $envelope['section'] ?? 'all'
        ));
    }
}
