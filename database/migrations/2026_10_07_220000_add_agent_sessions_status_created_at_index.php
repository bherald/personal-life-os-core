<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idx_agent_sessions_status_created_at';

    private const COLUMNS = ['status', 'created_at'];

    public function up(): void
    {
        if (! Schema::hasTable('agent_sessions')) {
            throw new RuntimeException('Agent sessions table required for status recency index.');
        }
        if ($this->existing()) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $wait = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
            DB::statement('SET SESSION lock_wait_timeout = 30');
            try {
                DB::statement('ALTER TABLE agent_sessions ADD INDEX '.self::INDEX.' (status, created_at), ALGORITHM=INPLACE, LOCK=NONE');
            } finally {
                DB::statement('SET SESSION lock_wait_timeout = '.$wait);
            }

            return;
        }

        Schema::table('agent_sessions', function ($table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    private function existing(): bool
    {
        foreach (Schema::getIndexes('agent_sessions') as $index) {
            if ($index['name'] !== self::INDEX) {
                continue;
            }
            if ($index['columns'] !== self::COLUMNS || $index['unique'] || $index['primary']) {
                throw new RuntimeException('Conflicting agent sessions status recency index; preserve existing definition.');
            }

            return true;
        }

        return false;
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_sessions') && $this->existing()) {
            Schema::table('agent_sessions', function ($table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
