<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idx_runs_completed_job';

    private const COLUMNS = ['completed_at', 'scheduled_job_id'];

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_job_runs')) {
            throw new RuntimeException('Scheduled run table required for daily recovery index.');
        }
        if ($this->existing()) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            $wait = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
            DB::statement('SET SESSION lock_wait_timeout = 30');
            try {
                DB::statement('ALTER TABLE scheduled_job_runs ADD INDEX '.self::INDEX.' (completed_at, scheduled_job_id), ALGORITHM=INPLACE, LOCK=NONE');
            } finally {
                DB::statement('SET SESSION lock_wait_timeout = '.$wait);
            }

            return;
        }
        Schema::table('scheduled_job_runs', function (Blueprint $table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    private function existing(): bool
    {
        foreach (Schema::getIndexes('scheduled_job_runs') as $index) {
            if ($index['name'] !== self::INDEX) {
                continue;
            }
            if ($index['columns'] !== self::COLUMNS || $index['unique'] || $index['primary']) {
                throw new RuntimeException('Conflicting daily recovery index; preserve existing definition.');
            }

            return true;
        }

        return false;
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_job_runs') && $this->existing()) {
            Schema::table('scheduled_job_runs', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
