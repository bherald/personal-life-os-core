<?php

namespace App\Console\Commands;

use App\Services\FileRegistryDuplicateCandidateMaterializationService;
use Illuminate\Console\Command;

class FileRegistryMaterializeDuplicateCandidatesCommand extends Command
{
    protected $signature = 'files:materialize-duplicate-candidates
        {--execute : Write file_registry_duplicates review rows}
        {--confirm= : Required token for execute mode}
        {--limit=1000 : Maximum exact duplicate groups to inspect}
        {--json : Emit machine-readable JSON}
        {--compact : Omit path samples and raw error detail from output}';

    protected $description = 'Classify exact file duplicate groups and materialize review metadata without moving or deleting files';

    public function handle(FileRegistryDuplicateCandidateMaterializationService $service): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && $this->option('confirm') !== FileRegistryDuplicateCandidateMaterializationService::CONFIRM_TOKEN) {
            $this->error('Execute mode requires --confirm='.FileRegistryDuplicateCandidateMaterializationService::CONFIRM_TOKEN);

            return self::FAILURE;
        }

        $result = $service->materializeCandidates(
            limit: (int) $this->option('limit'),
            dryRun: ! $execute
        );
        $hasErrors = ! empty($result['errors']);

        if ($this->option('compact')) {
            $result = $this->compactResult($result);
        }

        if ($this->option('json')) {
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode duplicate-candidate result JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->renderText($result);

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    private function compactResult(array $result): array
    {
        return [
            'status' => $result['status'] ?? 'unknown',
            'dry_run' => (bool) ($result['dry_run'] ?? true),
            'compact' => true,
            'posture' => [
                'aggregate_only' => true,
                'samples_included' => false,
                'paths_included' => false,
                'raw_errors_included' => false,
                'file_mutation_enabled' => false,
                'review_metadata_write_enabled' => ! (bool) ($result['dry_run'] ?? true),
            ],
            'groups_scanned' => (int) ($result['groups_scanned'] ?? 0),
            'groups_planned' => (int) ($result['groups_planned'] ?? 0),
            'groups_with_existing_pairs' => (int) ($result['groups_with_existing_pairs'] ?? 0),
            'pairs_planned' => (int) ($result['pairs_planned'] ?? 0),
            'pairs_inserted' => (int) ($result['pairs_inserted'] ?? 0),
            'pairs_existing' => (int) ($result['pairs_existing'] ?? 0),
            'candidate_bytes' => (int) ($result['candidate_bytes'] ?? 0),
            'by_classification' => $result['by_classification'] ?? [],
            'by_status' => $result['by_status'] ?? [],
            'sample_count' => count($result['samples'] ?? []),
            'error_count' => count($result['errors'] ?? []),
        ];
    }

    private function renderText(array $result): void
    {
        $this->line(sprintf(
            'duplicate-candidates: status=%s groups_scanned=%s groups_planned=%s pairs_planned=%s pairs_inserted=%s pairs_existing=%s candidate_bytes=%s',
            $result['status'] ?? 'unknown',
            $result['groups_scanned'] ?? 0,
            $result['groups_planned'] ?? 0,
            $result['pairs_planned'] ?? 0,
            $result['pairs_inserted'] ?? 0,
            $result['pairs_existing'] ?? 0,
            $result['candidate_bytes'] ?? 0
        ));

        foreach (($result['by_classification'] ?? []) as $classification => $count) {
            $this->line("classification {$classification}: {$count}");
        }

        foreach (($result['by_status'] ?? []) as $status => $count) {
            $this->line("review_status {$status}: {$count}");
        }

        foreach (($result['errors'] ?? []) as $error) {
            $this->warn('error '.json_encode($error, JSON_UNESCAPED_SLASHES));
        }
    }
}
