<?php

namespace App\Console\Commands;

use App\Services\JoplinYouTubeOrganizer;
use App\Support\JoplinPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class JoplinTopicOrganize extends Command
{
    protected $signature = 'joplin:topic-organize
                            {--write : Apply the planned moves and source tags}
                            {--no-source-tags : Do not add provable source tags}';

    protected $description = 'Organize the complete Joplin library into stable topic notebooks without deleting items';

    public function handle(JoplinYouTubeOrganizer $organizer): int
    {
        $write = (bool) $this->option('write');
        if ($write && ! $this->safeToWrite()) {
            return self::FAILURE;
        }

        $stats = $organizer->organizeLibraryToTopics(
            dryRun: ! $write,
            applySourceTags: ! $this->option('no-source-tags'),
        );

        $this->table(['Topic', 'Notes'], array_map(
            fn ($topic, $count) => [$topic, $count],
            array_keys($stats['by_topic']),
            array_values($stats['by_topic']),
        ));
        $this->table(['Metric', 'Value'], [
            ['Total Notes', $stats['total_notes']],
            ['Topic Notebooks Created', $stats['topics_created']],
            ['Notes Moved', $stats['notes_moved']],
            ['Legacy Notebooks Archived', $stats['notebooks_archived']],
            ['Source Tags Created', $stats['source_tags_created']],
            ['Source Tags Added', $stats['source_tags_added']],
            ['Failed Operations', $stats['failed_operations']],
            ['Mode', $write ? 'WRITE' : 'DRY RUN'],
        ]);
        $this->line('[ITEMS_PROCESSED:'.($stats['notes_moved'] + $stats['notebooks_archived'] + $stats['source_tags_added']).']');

        return $stats['failed_operations'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function safeToWrite(): bool
    {
        $queueCount = (int) DB::table('joplin_queue_jobs')
            ->whereIn('status', ['pending', 'processing'])
            ->count();
        if ($queueCount > 0) {
            $this->error("Joplin write refused: {$queueCount} queue job(s) are pending or processing.");

            return false;
        }

        $root = JoplinPaths::localRoot();
        $lockDir = $root ? $root.'/locks' : null;
        $locks = $lockDir && is_dir($lockDir)
            ? array_values(array_diff(scandir($lockDir) ?: [], ['.', '..']))
            : [];
        if ($locks !== []) {
            $this->error('Joplin write refused: active sync-target lock files are present.');

            return false;
        }

        return true;
    }
}
