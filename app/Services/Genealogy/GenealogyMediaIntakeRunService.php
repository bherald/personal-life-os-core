<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\Artisan;

class GenealogyMediaIntakeRunService
{
    private const VERSION = 1;

    public function __construct(
        private readonly GenealogyMediaIntakeReportService $report,
        private readonly GenealogyTreeRootResolver $rootResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(array $options): array
    {
        $treeId = (int) ($options['tree_id'] ?? 4);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $root = $this->normalizeRoot($treeId, $options['root'] ?? null, inferFromMedia: ! $dryRun);
        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));
        $compact = (bool) ($options['compact'] ?? false);
        $stage = (bool) ($options['stage'] ?? false);
        $saveRun = (bool) ($options['save_run'] ?? false);
        $confirmNoncanonicalWrite = (bool) ($options['confirm_noncanonical_write'] ?? false);
        $transcribeDryRun = (bool) ($options['transcribe_dry_run'] ?? false);
        $enrichDryRun = (bool) ($options['enrich_dry_run'] ?? false);
        $evidenceAssets = (bool) ($options['evidence_assets'] ?? false);
        $capturePreflight = (bool) ($options['capture_preflight'] ?? false);
        $postCaptureDryRun = (bool) ($options['post_capture_dry_run'] ?? false);

        $payload = [
            'version' => self::VERSION,
            'command' => 'genealogy:media-intake-run',
            'mode' => 'orchestrate',
            'dry_run' => $dryRun,
            'read_only' => ! ($saveRun && $confirmNoncanonicalWrite),
            'download_attempted' => false,
            'mutation_allowed' => $saveRun && $confirmNoncanonicalWrite,
            'canonical_write_allowed' => false,
            'noncanonical_write_allowed' => $saveRun && $confirmNoncanonicalWrite,
            'captured_at' => now()->toIso8601String(),
            'tree_id' => $treeId,
            'root' => $root,
            'root_hash' => substr(sha1($root), 0, 16),
            'limit' => $limit,
            'status' => 'planned',
            'blocked' => [],
            'steps' => [],
            'posture' => $this->posture($saveRun && $confirmNoncanonicalWrite),
        ];

        if ($saveRun && ! $stage) {
            $payload['blocked'][] = [
                'code' => 'save_run_requires_stage',
                'label' => '--save-run requires --stage so a staged intake snapshot exists.',
            ];
        }

        if ($saveRun && ! $confirmNoncanonicalWrite) {
            $payload['blocked'][] = [
                'code' => 'noncanonical_write_confirmation_required',
                'label' => '--save-run writes genealogy_intake_runs and requires --confirm-noncanonical-write.',
            ];
        }

        if ($payload['blocked'] !== []) {
            $payload['status'] = 'blocked';

            return $compact ? $this->compactPayload($payload) : $payload;
        }

        $payload['report'] = $this->report->compactPayload($this->report->collect($treeId, $root, $limit, $dryRun));
        $plannedSteps = $this->plannedSteps(
            $treeId,
            $root,
            $limit,
            $stage,
            $saveRun,
            $transcribeDryRun || $postCaptureDryRun,
            $enrichDryRun || $postCaptureDryRun,
            $evidenceAssets,
            $capturePreflight || $postCaptureDryRun,
            $postCaptureDryRun
        );

        if ($dryRun || $plannedSteps === []) {
            $payload['status'] = $dryRun ? 'dry_run' : 'no_steps_selected';
            $payload['steps'] = array_map(fn (array $step): array => $this->plannedStepPayload($step), $plannedSteps);

            return $compact ? $this->compactPayload($payload) : $payload;
        }

        foreach ($plannedSteps as $step) {
            $payload['steps'][] = $this->executeStep($step, $compact);
        }

        $failed = array_values(array_filter($payload['steps'], static fn (array $step): bool => ($step['exit_code'] ?? 0) !== 0));
        $payload['status'] = $failed === [] ? 'completed' : 'completed_with_errors';

        return $compact ? $this->compactPayload($payload) : $payload;
    }

    public function toText(array $payload): string
    {
        $lines = [
            'Genealogy media intake run',
            'Status: '.($payload['status'] ?? 'unknown').' | Tree: '.($payload['tree_id'] ?? 'unknown'),
            'Canonical writes allowed: '.(($payload['canonical_write_allowed'] ?? false) ? 'yes' : 'no'),
            'Noncanonical writes allowed: '.(($payload['noncanonical_write_allowed'] ?? false) ? 'yes' : 'no'),
        ];

        if (! empty($payload['blocked'])) {
            $lines[] = 'Blocked:';
            foreach ($payload['blocked'] as $blocker) {
                $lines[] = '  - '.($blocker['code'] ?? 'blocker').': '.($blocker['label'] ?? '');
            }
        }

        if (! empty($payload['steps'])) {
            $lines[] = 'Steps:';
            foreach ($payload['steps'] as $step) {
                $lines[] = '  - '.($step['code'] ?? 'step').': '.($step['status'] ?? 'unknown').' exit='.($step['exit_code'] ?? 'n/a');
            }
        }

        $lines[] = 'Posture: no downloads, no storage writes, no genealogy links, no review decisions, no canonical writes.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plannedSteps(
        int $treeId,
        string $root,
        int $limit,
        bool $stage,
        bool $saveRun,
        bool $transcribeDryRun,
        bool $enrichDryRun,
        bool $evidenceAssets,
        bool $capturePreflight,
        bool $postCaptureDryRun
    ): array {
        $steps = [];

        if ($stage) {
            $args = ['--stage' => true, '--tree' => $treeId, '--folder' => $root, '--limit' => $limit, '--unprocessed-only' => true];
            if ($saveRun) {
                $args['--save-run'] = true;
            }

            $steps[] = [
                'code' => $saveRun ? 'stage_save_run' : 'stage_preview',
                'label' => $saveRun ? 'Stage unprocessed media and persist a resumable intake run.' : 'Stage unprocessed media without persisting a run.',
                'command' => 'genealogy:ingest-documents',
                'args' => $args,
                'command_template' => 'php artisan genealogy:ingest-documents --stage'.($saveRun ? ' --save-run' : '').' --tree={tree} --folder={root} --limit={limit} --unprocessed-only',
                'write_required' => $saveRun,
                'canonical_write' => false,
            ];
        }

        if ($capturePreflight) {
            $steps[] = [
                'code' => $postCaptureDryRun ? 'post_capture_preflight' : 'capture_preflight',
                'label' => $postCaptureDryRun
                    ? 'Verify approved capture rows before downstream HTR/enrichment checks.'
                    : 'Inspect approved evidence capture rows without downloads, storage writes, or links.',
                'command' => 'genealogy:evidence-asset-capture-executor',
                'args' => ['--json' => true, '--compact' => true, '--limit' => $limit],
                'command_template' => 'php artisan genealogy:evidence-asset-capture-executor --json --compact --limit={limit}',
                'write_required' => false,
                'canonical_write' => false,
            ];
        }

        if ($transcribeDryRun) {
            $steps[] = [
                'code' => 'transcribe_dry_run',
                'label' => 'List HTR-eligible media without writing transcript text.',
                'command' => 'genealogy:transcribe-media',
                'args' => ['--dry-run' => true, '--tree' => $treeId, '--limit' => $limit],
                'command_template' => 'php artisan genealogy:transcribe-media --dry-run --tree={tree} --limit={limit}',
                'write_required' => false,
                'canonical_write' => false,
            ];
        }

        if ($enrichDryRun) {
            $steps[] = [
                'code' => 'enrich_dry_run',
                'label' => 'List enrichment-eligible media without generating proposals or links.',
                'command' => 'genealogy:enrich-media',
                'args' => ['--dry-run' => true, '--tree' => $treeId, '--limit' => $limit],
                'command_template' => 'php artisan genealogy:enrich-media --dry-run --tree={tree} --limit={limit}',
                'write_required' => false,
                'canonical_write' => false,
            ];
        }

        if ($evidenceAssets) {
            $steps[] = [
                'code' => 'evidence_assets',
                'label' => 'Inspect pending review packet evidence assets without downloads or writes.',
                'command' => 'genealogy:evidence-asset-candidates',
                'args' => ['--json' => true, '--compact' => true],
                'command_template' => 'php artisan genealogy:evidence-asset-candidates --json --compact',
                'write_required' => false,
                'canonical_write' => false,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function plannedStepPayload(array $step): array
    {
        return [
            'code' => $step['code'],
            'label' => $step['label'],
            'status' => 'planned',
            'command_template' => $step['command_template'],
            'write_required' => (bool) $step['write_required'],
            'canonical_write' => (bool) $step['canonical_write'],
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function executeStep(array $step, bool $compact): array
    {
        $exitCode = Artisan::call((string) $step['command'], (array) $step['args']);
        $output = (string) Artisan::output();

        $result = $this->plannedStepPayload($step);
        $result['status'] = $exitCode === 0 ? 'completed' : 'failed';
        $result['exit_code'] = $exitCode;

        if (! $compact) {
            $result['output_preview'] = mb_substr($output, 0, 4000);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function compactPayload(array $payload): array
    {
        unset($payload['root']);
        if (isset($payload['report']) && is_array($payload['report'])) {
            unset($payload['report']['root']);
        }

        foreach ($payload['steps'] as &$step) {
            unset($step['output_preview']);
        }
        unset($step);

        $payload['compact'] = true;

        return $payload;
    }

    private function posture(bool $noncanonicalWriteAllowed): array
    {
        return [
            'downloads_enabled' => false,
            'storage_writes_enabled' => false,
            'genealogy_links_enabled' => false,
            'review_decisions_enabled' => false,
            'capture_execution_enabled' => false,
            'canonical_writes_enabled' => false,
            'noncanonical_writes_enabled' => $noncanonicalWriteAllowed,
            'ai_calls_enabled_by_wrapper' => false,
        ];
    }

    private function normalizeRoot(int $treeId, mixed $root, bool $inferFromMedia = true): string
    {
        return $this->rootResolver->mediaRoot($treeId, $root, $inferFromMedia);
    }
}
