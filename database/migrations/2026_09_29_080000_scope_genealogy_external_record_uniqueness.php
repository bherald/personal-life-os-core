<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('genealogy_external_records')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX uk_service_record');
            DB::statement('CREATE UNIQUE INDEX uk_tree_service_record ON genealogy_external_records (tree_id, service_type, external_id)');

            return;
        }

        DB::statement('ALTER TABLE genealogy_external_records DROP INDEX uk_service_record, ADD UNIQUE KEY uk_tree_service_record (tree_id, service_type, external_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('genealogy_external_records')) {
            return;
        }

        $duplicates = DB::selectOne('SELECT 1 FROM genealogy_external_records WHERE service_type IS NOT NULL GROUP BY service_type, external_id HAVING COUNT(*) > 1 LIMIT 1');
        if ($duplicates !== null) {
            throw new RuntimeException('Cannot restore global external-record uniqueness while records are shared across trees');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX uk_tree_service_record');
            DB::statement('CREATE UNIQUE INDEX uk_service_record ON genealogy_external_records (service_type, external_id)');

            return;
        }

        DB::statement('ALTER TABLE genealogy_external_records DROP INDEX uk_tree_service_record, ADD UNIQUE KEY uk_service_record (service_type, external_id)');
    }
};
