<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerCatalogSyncService;
use Illuminate\Console\Command;

class ModelHostControllerCatalogSyncCommand extends Command
{
    protected $signature = 'model-host:catalog-sync
        {--execute : Persist the exact controller/table reconciliation}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Plan or persist a table-driven model-host controller catalog reconciliation';

    public function handle(ModelHostControllerCatalogSyncService $service): int
    {
        try {
            $result = $service->run((bool) $this->option('execute'));
        } catch (\Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'schema' => 'plos.model_host_controller.catalog_sync.v1',
                    'result' => 'rejected',
                    'error' => 'catalog_sync_failed',
                    'content_free' => true,
                    'contains_prompt_or_response_content' => false,
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Model-host catalog sync failed.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $summary = (array) $result['summary'];
            $this->info(sprintf(
                'Model-host catalog %s: total=%d inserted=%d updated=%d unchanged=%d planned_insert=%d planned_update=%d',
                $result['execute'] ? 'sync' : 'plan',
                $summary['total'],
                $summary['inserted'],
                $summary['updated'],
                $summary['unchanged'],
                $summary['planned_insert'],
                $summary['planned_update'],
            ));
        }

        return self::SUCCESS;
    }
}
