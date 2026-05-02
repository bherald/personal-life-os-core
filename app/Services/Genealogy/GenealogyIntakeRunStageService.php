<?php

namespace App\Services\Genealogy;

class GenealogyIntakeRunStageService
{
    public function __construct(
        private readonly GenealogyIntakeStagingService $stagingService,
        private readonly GenealogyIntakeRunStoreService $runStore
    ) {}

    /**
     * Stage documents from an arbitrary intake root and persist the saved run snapshot.
     * Safe by design: staging + save only, no copy execution or genealogy writes.
     */
    public function stage(int $treeId, string $rootPath, int $limit = 100, array $options = []): array
    {
        $rootPath = trim($rootPath);
        if ($rootPath === '') {
            return ['success' => false, 'error' => 'missing_root_path'];
        }

        $staged = $this->stagingService->stageScope($treeId, $rootPath, $limit, $options);
        if (! ($staged['success'] ?? false)) {
            return $staged;
        }

        $snapshot = $staged + ['status' => 'staged'];
        $saved = $this->runStore->saveStagedRun($snapshot);
        if (! ($saved['success'] ?? false)) {
            return $saved + ['run_key' => $staged['run_key'] ?? null];
        }

        $loaded = $this->runStore->getRun((string) ($staged['run_key'] ?? ''));

        return [
            'success' => true,
            'run' => ($loaded['success'] ?? false) ? (array) ($loaded['run'] ?? []) : $snapshot,
            'staged' => $staged,
        ];
    }
}
