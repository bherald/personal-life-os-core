<?php

namespace App\Jobs;

use App\Services\Genealogy\GedcomParserService;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteGenealogyMediaPathSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        private int $treeId,
        private string $gedcomPath
    ) {
        $this->onQueue('long-running');
    }

    public function handle(GenealogyService $genealogyService): void
    {
        Log::info('Queued genealogy media path sync started', [
            'tree_id' => $this->treeId,
            'gedcom_path' => $this->gedcomPath,
        ]);

        $parser = new GedcomParserService($this->gedcomPath);
        $data = $parser->parse();
        $result = $genealogyService->updateMediaPathsFromGedcom($this->treeId, $data['media'] ?? []);

        Log::info('Queued genealogy media path sync completed', [
            'tree_id' => $this->treeId,
            'gedcom_path' => $this->gedcomPath,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued genealogy media path sync failed', [
            'tree_id' => $this->treeId,
            'gedcom_path' => $this->gedcomPath,
            'error' => $exception?->getMessage(),
        ]);
    }
}
