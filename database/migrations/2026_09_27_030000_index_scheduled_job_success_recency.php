<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idx_job_status_completed';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_job_runs') || Schema::hasIndex('scheduled_job_runs', self::INDEX)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $lockWait = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
            DB::statement('SET SESSION lock_wait_timeout = 30');
            try {
                DB::statement('ALTER TABLE scheduled_job_runs ADD INDEX '.self::INDEX.' (scheduled_job_id, status, completed_at), ALGORITHM=INPLACE, LOCK=NONE');
            } finally {
                DB::statement('SET SESSION lock_wait_timeout = '.$lockWait);
            }

            return;
        }

        Schema::table('scheduled_job_runs', function (Blueprint $table): void {
            $table->index(['scheduled_job_id', 'status', 'completed_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_job_runs') && Schema::hasIndex('scheduled_job_runs', self::INDEX)) {
            Schema::table('scheduled_job_runs', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
