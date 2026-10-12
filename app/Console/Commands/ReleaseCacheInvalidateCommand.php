<?php

namespace App\Console\Commands;

use App\Services\AIService;
use App\Services\ComputeRouterService;
use App\Services\LLMPoolManagerService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;

class ReleaseCacheInvalidateCommand extends Command
{
    protected $signature = 'ops:release-cache-invalidate {--json : Emit fixed release invalidation receipt}';

    protected $description = 'Invalidate configuration and provider inventories without clearing custody or response caches.';

    public function handle(SystemConfigService $config): int
    {
        $report = ['schema' => 'plos.release_cache_invalidation.v1', 'success' => false, 'reason_code' => 'cache_invalidation_unavailable'];
        try {
            $config->flushCache(true);
            LLMPoolManagerService::clearInstanceCache(true);
            ComputeRouterService::clearInstanceCache(true);
            AIService::clearProviderInventoryCache(true);
            $report['success'] = true;
            $report['reason_code'] = null;
        } catch (\Throwable) {
            // Fixed diagnostics only; no configuration values, credentials or backend messages.
        }
        $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR)
            : ($report['success'] ? 'Release cache invalidation complete.' : 'Release cache invalidation held.'));

        return $report['success'] ? self::SUCCESS : self::FAILURE;
    }
}
