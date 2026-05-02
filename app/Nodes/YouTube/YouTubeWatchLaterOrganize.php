<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\JoplinYouTubeOrganizer;
use Illuminate\Support\Facades\Log;
use Exception;

class YouTubeWatchLaterOrganize extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $useAI = $this->getConfigValue('use_ai', true);
            $dryRun = $this->getConfigValue('dry_run', false);

            Log::info('YouTubeWatchLaterOrganize: Starting', [
                'use_ai' => $useAI,
                'dry_run' => $dryRun,
            ]);

            $organizer = app(JoplinYouTubeOrganizer::class);
            $stats = $organizer->organizeAll($dryRun, $useAI);

            Log::info('YouTubeWatchLaterOrganize: Complete', $stats);

            return $this->standardOutput(
                array_merge($input['data'] ?? $input, ['organization_stats' => $stats]),
                [
                    'notes_moved' => $stats['notes_moved'],
                    'duplicates_deleted' => $stats['duplicates_deleted'],
                    'categories_created' => $stats['categories_created'],
                    'ai_categorized' => $stats['ai_categorized'],
                    'failed_operations' => $stats['failed_operations'],
                ]
            );

        } catch (Exception $e) {
            Log::error('YouTubeWatchLaterOrganize: Failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput($input['data'] ?? $input, [], $e->getMessage());
        }
    }
}
