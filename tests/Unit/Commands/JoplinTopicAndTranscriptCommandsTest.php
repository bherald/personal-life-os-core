<?php

namespace Tests\Unit\Commands;

use App\Services\JoplinYouTubeOrganizer;
use Mockery;
use Tests\TestCase;

class JoplinTopicAndTranscriptCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_topic_organizer_defaults_to_complete_read_only_plan(): void
    {
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldReceive('organizeLibraryToTopics')->once()->with(true, true)->andReturn([
            'total_notes' => 12,
            'topics_created' => 3,
            'notes_moved' => 10,
            'notebooks_archived' => 2,
            'source_tags_created' => 1,
            'source_tags_added' => 8,
            'failed_operations' => 0,
            'dry_run' => true,
            'by_topic' => ['Inbox' => 12],
        ]);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);

        $this->artisan('joplin:topic-organize')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('[ITEMS_PROCESSED:20]')
            ->assertExitCode(0);
    }

    public function test_transcript_repair_defaults_to_read_only_audit(): void
    {
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldReceive('repairStoredTranscripts')->once()->with(true, 0.8)->andReturn([
            'stored_transcripts' => 93,
            'youtube_notes' => 818,
            'matched_notes' => 113,
            'repair_candidates' => 81,
            'repaired' => 0,
            'unchanged' => 32,
            'unrepairable' => 0,
            'failed_operations' => 0,
            'dry_run' => true,
        ]);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);

        $this->artisan('youtube:repair-joplin-transcripts')
            ->expectsOutputToContain('Repair Candidates')
            ->expectsOutputToContain('[ITEMS_PROCESSED:0]')
            ->assertExitCode(0);
    }

    public function test_archived_original_review_flag_cannot_enable_bulk_overwrite(): void
    {
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldNotReceive('repairStoredTranscripts');
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);
        $this->artisan('youtube:repair-joplin-transcripts', ['--write' => true, '--preserve-edited-transcript' => true])
            ->expectsOutput('Reviewed archived-original append requires exact video, note and source selectors.')
            ->assertExitCode(1);
    }
}
