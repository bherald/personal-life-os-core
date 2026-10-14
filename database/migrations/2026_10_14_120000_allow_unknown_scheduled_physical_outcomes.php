<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE scheduled_job_runs MODIFY status ENUM('running','success','failed','timeout','unknown') NOT NULL DEFAULT 'running'");
        DB::statement("ALTER TABLE scheduled_jobs MODIFY last_run_status ENUM('success','failed','running','timeout','unknown') NULL DEFAULT NULL");
    }

    public function down(): void
    {
        // Never coerce retained UNKNOWN history into a fabricated outcome.
        if (DB::table('scheduled_job_runs')->where('status', 'unknown')->exists()
            || DB::table('scheduled_jobs')->where('last_run_status', 'unknown')->exists()) {
            throw new RuntimeException('Resolve scheduled UNKNOWN history before removing its schema support.');
        }
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE scheduled_job_runs MODIFY status ENUM('running','success','failed','timeout') NOT NULL DEFAULT 'running'");
        DB::statement("ALTER TABLE scheduled_jobs MODIFY last_run_status ENUM('success','failed','running','timeout') NULL DEFAULT NULL");
    }
};
