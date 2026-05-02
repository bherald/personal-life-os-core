<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('scheduled_jobs')
                ->where('name', 'workflow_joplin_sync')
                ->update([
                    'job_type' => 'command',
                    'command' => 'joplin:sync --limit=100 --no-ansi',
                    'description' => 'Run bounded Joplin note sync directly',
                    'notes' => 'Legacy workflow ID 4 retired; scheduled job now runs bounded direct Joplin sync.',
                    'timeout_minutes' => 45,
                    'stall_exempt' => 1,
                    'updated_at' => now(),
                ]);

            DB::table('workflow_nodes')
                ->where('workflow_id', 4)
                ->delete();

            DB::table('workflows')
                ->where('id', 4)
                ->where('name', 'joplin_sync')
                ->delete();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('workflows')->updateOrInsert(
                ['id' => 4],
                [
                    'name' => 'joplin_sync',
                    'description' => 'Sync Joplin notes from Nextcloud to RAG every 12 hours',
                    'schedule' => '0 4,22 * * *',
                    'active' => 1,
                    'current_version' => 1,
                    'error_handling' => 'continue',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('workflow_nodes')->updateOrInsert(
                ['id' => 83],
                [
                    'workflow_id' => 4,
                    'node_type' => 'JoplinSync',
                    'node_order' => 1,
                    'timeout_seconds' => 900,
                    'compensation_handler' => null,
                    'compensation_config' => null,
                    'created_at' => now(),
                ]
            );

            DB::table('workflow_nodes')->updateOrInsert(
                ['id' => 84],
                [
                    'workflow_id' => 4,
                    'node_type' => 'AIFormatter',
                    'node_order' => 2,
                    'timeout_seconds' => 600,
                    'compensation_handler' => null,
                    'compensation_config' => null,
                    'created_at' => now(),
                ]
            );

            DB::table('scheduled_jobs')
                ->where('name', 'workflow_joplin_sync')
                ->update([
                    'job_type' => 'workflow',
                    'command' => 'joplin_sync',
                    'description' => 'Run workflow: joplin_sync',
                    'notes' => 'Migrated from workflows table. Original workflow ID: 4',
                    'timeout_minutes' => 90,
                    'stall_exempt' => 1,
                    'updated_at' => now(),
                ]);
        });
    }
};
