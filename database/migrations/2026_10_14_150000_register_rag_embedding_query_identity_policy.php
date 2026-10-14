<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'rag', 'config_key' => 'embedding_query_policy',
            'config_value' => json_encode(['schema' => 'plos.rag_embedding_query_policy.v1', 'allow_unknown_identity' => true]),
            'data_type' => 'json',
            'description' => 'Vector queries reject known provider/model/revision/dimension mismatches. Preserve existing unknown legacy identity admission; lexical retrieval is independent.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain operator-owned unknown-identity policy on code rollback.
    }
};
