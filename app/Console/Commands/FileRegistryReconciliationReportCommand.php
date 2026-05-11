<?php

namespace App\Console\Commands;

use App\Services\FileRegistryReconciliationReportService;
use Illuminate\Console\Command;

class FileRegistryReconciliationReportCommand extends Command
{
    protected $signature = 'files:reconcile-lifecycle
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Emit compact status-check summary}
        {--samples : Include bounded sample rows; may include local paths}
        {--limit=10 : Maximum sample rows to inspect or display}';

    protected $description = 'Read-only file lifecycle reconciliation report for move/delete/orphan evidence';

    public function handle(FileRegistryReconciliationReportService $report): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $report->collect(
            sampleLimit: (int) $this->option('limit'),
            includeSamples: (bool) $this->option('samples')
        );

        if ($this->option('compact')) {
            $payload = $report->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode file reconciliation JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($report->toMarkdown($payload));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->renderCompactText($payload);

            return self::SUCCESS;
        }

        $this->renderText($payload);

        return self::SUCCESS;
    }

    private function renderText(array $payload): void
    {
        $counts = $payload['counts'] ?? [];
        $posture = $payload['posture'] ?? [];

        $this->line(sprintf(
            'File lifecycle reconciliation: %s mode=observe captured=%s samples=%s',
            $payload['status'] ?? 'unknown',
            $payload['captured_at'] ?? '-',
            ($payload['samples_included'] ?? false) ? 'yes' : 'no'
        ));
        $this->line(sprintf(
            'posture: read_only=%s writes_enabled=%s move_apply=%s delete_apply=%s rag_cleanup=%s face_cleanup=%s canonical_writeback=%s',
            ($posture['read_only'] ?? true) ? 'yes' : 'no',
            ($posture['writes_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['move_apply_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['delete_apply_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['rag_cleanup_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['face_cleanup_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['canonical_writeback_enabled'] ?? false) ? 'yes' : 'no'
        ));
        $this->line(sprintf(
            'identity: active=%s missing_identity_or_path=%s duplicate_asset_groups=%s duplicate_fileid_groups=%s same_content_multi_path=%s same_filename_content_multi_path=%s',
            $counts['active_files'] ?? 0,
            $counts['active_missing_identity_or_path'] ?? 0,
            $counts['duplicate_asset_uuid_groups'] ?? 0,
            $counts['duplicate_nextcloud_fileid_groups'] ?? 0,
            $counts['same_content_multi_path_groups'] ?? 0,
            $counts['same_filename_content_multi_path_groups'] ?? 0
        ));
        $this->line(sprintf(
            'downstream: mysql_orphan_rows=%s rag_file_documents=%s rag_checked=%s rag_missing_registry=%s',
            $counts['mysql_downstream_orphan_rows'] ?? 0,
            $counts['rag_file_documents'] ?? 0,
            ((int) ($counts['rag_source_id_checked_sample'] ?? 0)) + ((int) ($counts['rag_asset_uuid_checked_sample'] ?? 0)),
            ((int) ($counts['rag_source_id_missing_registry_in_sample'] ?? 0)) + ((int) ($counts['rag_asset_uuid_missing_registry_in_sample'] ?? 0))
        ));

        foreach (($payload['errors'] ?? []) as $error) {
            $this->warn('evidence_error: '.$error);
        }
    }

    private function renderCompactText(array $payload): void
    {
        $counts = $payload['counts'] ?? [];
        $posture = $payload['posture'] ?? [];

        $this->line(sprintf(
            'file-reconcile: %s active=%s move_or_duplicate_groups=%s identity_conflicts=%s missing_identity_or_path=%s mysql_orphans=%s rag_checked=%s rag_missing=%s errors=%s',
            $payload['status'] ?? 'unknown',
            $counts['active_files'] ?? 0,
            $counts['move_or_duplicate_candidate_groups'] ?? 0,
            $counts['identity_conflict_groups'] ?? 0,
            $counts['missing_identity_or_path'] ?? 0,
            $counts['mysql_downstream_orphan_rows'] ?? 0,
            $counts['rag_checked_sample'] ?? 0,
            $counts['rag_missing_registry_in_sample'] ?? 0,
            $counts['error_count'] ?? 0
        ));
        $this->line(sprintf(
            'posture: read_only=%s writes_enabled=%s move_apply=%s delete_apply=%s canonical_writeback=%s',
            ($posture['read_only'] ?? true) ? 'yes' : 'no',
            ($posture['writes_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['move_apply_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['delete_apply_enabled'] ?? false) ? 'yes' : 'no',
            ($posture['canonical_writeback_enabled'] ?? false) ? 'yes' : 'no'
        ));
    }
}
