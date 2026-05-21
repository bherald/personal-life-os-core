<?php

namespace App\Console\Commands;

use App\Services\Genealogy\SourceAudit\SourceAuditWorkbookService;
use Illuminate\Console\Command;

class GenealogySourceAuditWorkbookCommand extends Command
{
    protected $signature = 'genealogy:source-audit-workbook
        {--tree=4 : Tree ID to export}
        {--format=manifest : Output format: manifest, csv_zip, package, docx, or odt}
        {--privacy=private_local : Privacy mode: private_local, public_redacted, or audit_ids_only}
        {--layout=dense_audit_v1 : Layout profile for manifest/workbook metadata}
        {--no-sources : Omit source and citation sheets}
        {--no-media : Omit media sheet}
        {--no-issues : Omit issue and review-note sheets}
        {--prelabel-count=0 : Reserve this many pre-scan document IDs in prelabel_queue.csv}
        {--shard=none : Optional sharding mode: none or surname_initial}
        {--branch-person= : Optional root person ID for branch-filtered generation}
        {--branch-mode=descendants : Branch filter mode: descendants, ancestors, or family}
        {--confirm : Required to write files when not using --dry-run}
        {--json : Emit machine-readable JSON}
        {--dry-run : Dry-run preview of row counts and output plan without writing files}';

    protected $description = 'Generate the FT source-audit workbook manifest, CSV package, DOCX, or ODT for comparing documents against genealogy data';

    public function handle(SourceAuditWorkbookService $service): int
    {
        $dryRun = ! (bool) $this->option('confirm') || (bool) $this->option('dry-run');
        $format = (string) $this->option('format');

        try {
            $payload = $service->generate(
                treeId: (int) $this->option('tree'),
                format: $format,
                privacyMode: (string) $this->option('privacy'),
                dryRun: $dryRun,
                confirm: (bool) $this->option('confirm'),
                actor: 'artisan:generation-source-audit-workbook',
                layoutProfile: (string) $this->option('layout'),
                includeSources: ! (bool) $this->option('no-sources'),
                includeMedia: ! (bool) $this->option('no-media'),
                includeIssues: ! (bool) $this->option('no-issues'),
                prelabelCount: (int) $this->option('prelabel-count'),
                shardMode: (string) $this->option('shard'),
                branchPersonId: $this->option('branch-person') !== null ? (int) $this->option('branch-person') : null,
                branchMode: (string) $this->option('branch-mode')
            );
        } catch (\Throwable $e) {
            $payload = [
                'tool' => 'source_audit_workbook',
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($payload['success'] ?? false)) {
            $this->error((string) ($payload['error'] ?? 'Source-audit workbook generation failed.'));

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Source-audit workbook %s for tree %s [%s]',
            ($payload['dry_run'] ?? false) ? 'dry-run' : 'generated',
            (string) ($payload['tree_id'] ?? 'unknown'),
            (string) ($payload['format'] ?? $format)
        ));

        $counts = (array) ($payload['row_counts'] ?? $payload['counts'] ?? []);
        if ($counts !== []) {
            $this->table(
                ['Sheet', 'Rows'],
                array_map(
                    static fn (string $sheet, int $rows): array => [$sheet, $rows],
                    array_keys($counts),
                    array_values($counts)
                )
            );
        }

        if (! empty($payload['output_dir'])) {
            $this->line('Output: '.$payload['output_dir']);
        } elseif (! empty($payload['output_plan']['output_dir'])) {
            $this->line('Planned output: '.$payload['output_plan']['output_dir']);
        }

        return self::SUCCESS;
    }
}
