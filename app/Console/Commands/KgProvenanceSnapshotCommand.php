<?php

namespace App\Console\Commands;

use App\Services\KgProvenanceSnapshotService;
use Illuminate\Console\Command;

class KgProvenanceSnapshotCommand extends Command
{
    protected $signature = 'graph:snapshot-provenance
                            {--dry-run : Build the snapshot payload without writing}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Capture daily knowledge-graph provenance audit metrics into pipeline snapshots';

    public function handle(KgProvenanceSnapshotService $service): int
    {
        $payload = $service->capture((bool) $this->option('dry-run'));

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $snapshot = $payload['snapshot'];
        $this->info(sprintf(
            'KG provenance snapshot %s: pending=%d total=%d completion=%s%%',
            $snapshot['snapshot_date'],
            $snapshot['pending'],
            $snapshot['total'],
            number_format((float) $snapshot['completion_pct'], 2)
        ));

        return self::SUCCESS;
    }
}
