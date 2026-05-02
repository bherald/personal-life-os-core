<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'workflow_joplin_sync')
            ->update([
                'command' => 'joplin:sync --limit=10 --no-ansi',
                'notes' => 'Legacy workflow ID 4 retired; scheduled job runs a bounded direct Joplin sync and defers when fast embedding providers are unavailable.',
                'timeout_minutes' => 45,
                'stall_exempt' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'workflow_joplin_sync')
            ->update([
                'command' => 'joplin:sync --limit=100 --no-ansi',
                'notes' => 'Legacy workflow ID 4 retired; scheduled job now runs bounded direct Joplin sync.',
                'timeout_minutes' => 45,
                'stall_exempt' => 1,
                'updated_at' => now(),
            ]);
    }
};
