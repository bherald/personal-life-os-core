<?php

namespace App\Console\Commands;

use App\Services\JoplinYouTubeKnowledgeService;
use Illuminate\Console\Command;
use Throwable;

class JoplinYouTubeKnowledgeCommand extends Command
{
    protected $signature = 'joplin:youtube-knowledge
                            {--apply : Create the knowledge tree, move scoped notes and refresh managed indexes}
                            {--limit=0 : Bound source-note moves for a pilot; zero means all}
                            {--deduplicate : Remove only equivalent exact-video copies using guarded backups}
                            {--json : Emit the complete JSON report}';

    protected $description = 'Audit and organize YouTube knowledge while moving scoped ordinary topic notes to Inbox';

    public function handle(JoplinYouTubeKnowledgeService $service): int
    {
        try {
            $result = $service->run((bool) $this->option('apply'), (int) $this->option('limit'), (bool) $this->option('deduplicate'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('[ITEMS_PROCESSED:'.($result['applied_moves'] + $result['managed_notes_updated'] + ($result['duplicate_cleanup']['duplicates_removed'] ?? 0)).']');

            return ($result['failed_operations'] ?? 0) === 0 && ($result['managed_index_conflict_count'] ?? 0) === 0
                ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'blocked', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
