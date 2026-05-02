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

class ExecuteGenealogyFaceImportScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 1;

    public function __construct(
        private int $treeId,
        private string $folder,
        private bool $recursive = true
    ) {
        $this->onQueue('long-running');
    }

    public function handle(GenealogyMediaService $mediaService): void
    {
        Log::info('Queued genealogy face-import scan started', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'recursive' => $this->recursive,
        ]);

        $result = $mediaService->scanNextcloudFolderWithFaces(
            $this->treeId,
            $this->folder,
            $this->recursive
        );

        Log::info('Queued genealogy face-import scan completed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued genealogy face-import scan failed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'error' => $exception?->getMessage(),
        ]);
    }
}
