<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove placeholder 'agent' review type from review_type_registry.
 * Row has enabled=0, no service_class, no methods — dead config.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::delete("
            DELETE FROM review_type_registry
            WHERE name = 'agent'
              AND (service_class IS NULL OR service_class = '')
              AND enabled = 0
        ");
    }

    public function down(): void
    {
        $exists = DB::selectOne("SELECT 1 FROM review_type_registry WHERE name = 'agent' LIMIT 1");
        if (!$exists) {
            DB::insert("
                INSERT INTO review_type_registry (name, enabled, created_at, updated_at)
                VALUES ('agent', 0, NOW(), NOW())
            ");
        }
    }
};
