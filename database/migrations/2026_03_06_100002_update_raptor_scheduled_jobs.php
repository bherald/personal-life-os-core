<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N79: Update raptor_build batch size and add raptor_screen job.
 *
 * raptor_build: --limit=50 → --limit=200 (real queue is ~600 docs, clears in 3 runs)
 * raptor_screen: new job, screens unvetted docs for eligibility via heuristics + AI
 */
return new class extends Migration
{
    public function up(): void
    {
        // Update raptor_build to larger batch
        DB::table('scheduled_jobs')
            ->where('name', 'raptor_build')
            ->update(['command' => 'rag:raptor-build --limit=200']);

        // Add raptor_screen if it doesn't exist
        $exists = DB::table('scheduled_jobs')->where('name', 'raptor_screen')->exists();
        if (!$exists) {
            $row = [
                'name'            => 'raptor_screen',
                'command'         => 'rag:raptor-build --screen --limit=5000',
                'job_type'        => 'command',
                'cron_expression' => '0 */6 * * *',  // every 6 hours
                'enabled'         => true,
                'timeout_minutes' => 30,
                'category'        => 'RAG',
                'notes'           => 'Screens unvetted rag_documents for RAPTOR eligibility using heuristics + AI. Runs until all docs are classified.',
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
            // stall_exempt added in N75 migration — include only if column exists
            if (DB::getSchemaBuilder()->hasColumn('scheduled_jobs', 'stall_exempt')) {
                $row['stall_exempt'] = false;
            }
            DB::table('scheduled_jobs')->insert($row);
        }
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'raptor_build')
            ->update(['command' => 'rag:raptor-build --limit=50']);

        DB::table('scheduled_jobs')->where('name', 'raptor_screen')->delete();
    }
};
