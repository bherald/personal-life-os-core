<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'review_desk_references' => [
            'name' => 'idx_review_desk_pending_order',
            'columns' => ['status', 'created_at', 'reference'],
            'sql' => 'status, created_at, reference',
        ],
        'file_registry' => [
            'name' => 'idx_file_registry_original_path',
            'columns' => ['original_path'],
            'sql' => 'original_path(255)',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Review lookup table required: '.$table);
            }
            if ($this->existing($table, $index)) {
                continue;
            }
            if (DB::getDriverName() === 'mysql') {
                $wait = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
                DB::statement('SET SESSION lock_wait_timeout = 30');
                try {
                    DB::statement('ALTER TABLE '.$table.' ADD INDEX '.$index['name'].' ('.$index['sql'].'), ALGORITHM=INPLACE, LOCK=NONE');
                } finally {
                    DB::statement('SET SESSION lock_wait_timeout = '.$wait);
                }
            } else {
                Schema::table($table, fn ($blueprint) => $blueprint->index($index['columns'], $index['name']));
            }
        }
    }

    private function existing(string $table, array $expected): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] !== $expected['name']) {
                continue;
            }
            if ($index['columns'] !== $expected['columns'] || $index['unique'] || $index['primary']) {
                throw new RuntimeException('Conflicting review lookup index; preserve existing definition: '.$expected['name']);
            }
            if ($table === 'file_registry' && DB::getDriverName() === 'mysql') {
                $rows = DB::select('SHOW INDEX FROM file_registry WHERE Key_name = ?', [$expected['name']]);
                if (count($rows) !== 1 || (int) $rows[0]->Sub_part !== 255) {
                    throw new RuntimeException('Conflicting original-path index prefix; preserve existing definition.');
                }
            }

            return true;
        }

        return false;
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (Schema::hasTable($table) && $this->existing($table, $index)) {
                Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($index['name']));
            }
        }
    }
};
