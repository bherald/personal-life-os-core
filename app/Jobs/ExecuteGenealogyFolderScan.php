<?php

namespace App\Jobs;

use App\Services\Genealogy\GenealogyMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteGenealogyFolderScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 1;

    public function __construct(
        private int $treeId,
        private string $folder,
        private bool $recursive = true,
        private bool $filterForMatches = false
    ) {
        $this->onQueue('long-running');
    }

    public function handle(GenealogyMediaService $mediaService): void
    {
        Log::info('Queued genealogy folder scan started', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'recursive' => $this->recursive,
            'filter_for_matches' => $this->filterForMatches,
        ]);

        $result = $mediaService->scanNextcloudFolder(
            $this->treeId,
            $this->folder,
            $this->recursive,
            $this->filterForMatches
        );

        Log::info('Queued genealogy folder scan completed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued genealogy folder scan failed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'error' => $exception?->getMessage(),
        ]);
    }
}
