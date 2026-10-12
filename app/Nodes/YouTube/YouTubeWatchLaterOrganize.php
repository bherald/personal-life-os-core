<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\JoplinYouTubeKnowledgeService;
use Exception;
use Illuminate\Support\Facades\Log;

class YouTubeWatchLaterOrganize extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $dryRun = $this->resolveBooleanConfig('dry_run', false);
            $stats = app(JoplinYouTubeKnowledgeService::class)->run(! $dryRun, 0, $this->resolveBooleanConfig('deduplicate', false));

            return $this->standardOutput(
                array_merge($input['data'] ?? $input, ['organization_stats' => $stats]),
                ['notes_moved' => $stats['applied_moves'], 'failed_operations' => $stats['failed_operations']],
            );

        } catch (Exception $e) {
            Log::error('YouTubeWatchLaterOrganize: Failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput($input['data'] ?? $input, [], $e->getMessage());
        }
    }
}
