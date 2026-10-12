<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_COMMAND = 'joplin:youtube-knowledge --apply --json';

    private const COMMAND = 'joplin:youtube-knowledge --apply --deduplicate --json';

    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'joplin_youtube_organize')
            ->where('command', self::PREVIOUS_COMMAND)
            ->update(['command' => self::COMMAND, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'joplin_youtube_organize')
            ->where('command', self::COMMAND)
            ->update(['command' => self::PREVIOUS_COMMAND, 'updated_at' => now()]);
    }
};
