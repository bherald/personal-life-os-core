<?php

namespace App\Console\Commands;

use App\Services\JoplinYouTubeOrganizer;
use App\Services\YouTubeJoplinDeliveryService;
use App\Support\JoplinPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class YouTubeRepairJoplinTranscripts extends Command
{
    protected $signature = 'youtube:repair-joplin-transcripts
                            {--write : Apply selected repair or exact custody reconciliation}
                            {--min-ratio=0.8 : Minimum acceptable Joplin-to-source character ratio}
                            {--video-id= : Exact-video missing-original retention pilot}
                            {--note-id= : Exact existing note for the retention pilot}
                            {--source-id= : Exact youtube_transcripts row for the retention pilot}
                            {--expected-wire-hash= : Reviewed preview hash required for pilot write}
                            {--expected-source-hash= : Reviewed transcript hash required for pilot write}
                            {--reconcile-custody-id= : Reconcile one uncertain source write by exact fresh read-back; never write the note}
                            {--expected-custody-hash= : Reviewed owner/envelope hash required for custody reconciliation}
                            {--preserve-edited-transcript : Explicit reviewed append of an archived original beside an existing transcript}';

    protected $description = 'Audit or restore incomplete YouTube transcript sections in Joplin notes';

    public function handle(JoplinYouTubeOrganizer $organizer): int
    {
        $write = (bool) $this->option('write');
        if ($this->option('reconcile-custody-id')) {
            try {
                $result = app(YouTubeJoplinDeliveryService::class)->reconcileSourceWrite(
                    (int) $this->option('reconcile-custody-id'), (string) $this->option('video-id'),
                    (string) $this->option('note-id'), (int) $this->option('source-id'), $write, $this->option('expected-custody-hash'),
                );
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return $result['status'] === 'hold' ? self::FAILURE : self::SUCCESS;
            } catch (\Throwable) {
                $this->error('Exact source write reconciliation held; no note replay or completion receipt granted.');

                return self::FAILURE;
            }
        }
        if ($this->option('preserve-edited-transcript') && (! $this->option('video-id') || ! $this->option('note-id') || ! $this->option('source-id'))) {
            $this->error('Reviewed archived-original append requires exact video, note and source selectors.');

            return self::FAILURE;
        }
        if ($this->option('video-id') || $this->option('note-id') || $this->option('source-id')) {
            try {
                $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal(
                    (string) $this->option('video-id'), (string) $this->option('note-id'),
                    (int) $this->option('source-id'), $write, $this->option('expected-wire-hash'), $this->option('expected-source-hash'),
                    preserveEditedTranscript: (bool) $this->option('preserve-edited-transcript'),
                );
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return $result['status'] === 'hold' ? self::FAILURE : self::SUCCESS;
            } catch (\Throwable) {
                $this->error('Exact source retention pilot blocked; no completion receipt granted.');

                return self::FAILURE;
            }
        }
        $ratio = (float) $this->option('min-ratio');
        if ($ratio <= 0 || $ratio > 1) {
            $this->error('--min-ratio must be greater than 0 and at most 1.');

            return self::FAILURE;
        }

        if ($write && ! $this->safeToWrite()) {
            return self::FAILURE;
        }

        $stats = $organizer->repairStoredTranscripts(! $write, $ratio);
        $this->table(['Metric', 'Value'], array_map(
            fn ($key, $value) => [str_replace('_', ' ', ucwords($key, '_')), is_bool($value) ? ($value ? 'Yes' : 'No') : $value],
            array_keys($stats),
            array_values($stats),
        ));
        $this->line('[ITEMS_PROCESSED:'.$stats['repaired'].']');

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
