<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Only use on each test's private SQLite connection. */
trait CreatesYouTubeSourceCustodySchema
{
    private function createKeyPointTranscriptSchema(array $rows): void
    {
        Schema::create('youtube_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->string('video_id');
            $table->text('content');
        });
        DB::table('youtube_transcripts')->insert($rows);
    }

    private function createSourceCustodySchema(): void
    {
        Schema::create('task_custody_records', function (Blueprint $table): void {
            $table->id();
            $table->string('surface');
            $table->string('surface_ref');
            $table->string('owner_token');
            $table->text('result_envelope')->nullable();
            $table->string('progress_note')->nullable();
            $table->string('outcome')->nullable();
            $table->string('notification_state')->nullable();
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX source_custody_active ON task_custody_records(surface, surface_ref) WHERE released_at IS NULL');
    }
}
