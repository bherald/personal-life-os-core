<?php

namespace App\Console\Commands;

use App\Services\JoplinYouTubeRewriteService;
use Illuminate\Console\Command;
use Throwable;

class JoplinYouTubeRewriteCommand extends Command
{
    protected $signature = 'joplin:youtube-rewrite
                            {bundle : Private canonical-note rewrite bundle JSON}
                            {--apply : Rewrite canonical notes and remove verified duplicate notes}
                            {--limit=0 : Bound videos for a pilot; zero means all}
                            {--json : Emit JSON}';

    protected $description = 'Rewrite YouTube notes into canonical readable notes and consolidate exact-video duplicates';

    public function handle(JoplinYouTubeRewriteService $service): int
    {
        try {
            $result = $service->run(
                (string) $this->argument('bundle'),
                (bool) $this->option('apply'),
                (int) $this->option('limit'),
            );
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('[ITEMS_PROCESSED:'.($result['updated'] + $result['duplicates_removed']).']');

            return ($result['failed_operations'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode(['status' => 'blocked', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
