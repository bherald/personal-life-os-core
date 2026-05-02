<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_media_consolidate')
            ->update([
                'command' => 'genealogy:media-consolidate --tree-id=4 --batch=50 --delay=500 --timeout=30',
                'without_overlapping' => 1,
                'timeout_minutes' => DB::raw('GREATEST(COALESCE(timeout_minutes, 0), 30)'),
                'runtime_mode' => 'maintenance',
                'workload_family' => 'genealogy',
                'resource_profile' => 'nextcloud',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_media_external_links',
                'notification_mode' => 'digest',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_media_consolidate')
            ->update([
                'command' => 'genealogy:media-consolidate --tree-id=4 --batch=50 --delay=500',
                'updated_at' => now(),
            ]);
    }
};
