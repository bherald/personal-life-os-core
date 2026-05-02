<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add file_registry_face_id to face_embeddings table.
 * Links pgvector embeddings back to the specific MySQL face record,
 * enabling the unified clustering UI to bridge both databases.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Add file_registry_face_id column (references MySQL file_registry_faces.id)
        DB::connection($this->connection)->statement("
            ALTER TABLE face_embeddings
            ADD COLUMN IF NOT EXISTS file_registry_face_id BIGINT
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_embeddings_frf_id
            ON face_embeddings(file_registry_face_id)
        ");

        // Add unique constraint to prevent duplicate pgvector rows per MySQL face
        DB::connection($this->connection)->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_face_embeddings_frf_unique
            ON face_embeddings(file_registry_face_id)
            WHERE file_registry_face_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_face_embeddings_frf_unique
        ");
        DB::connection($this->connection)->statement("
            DROP INDEX IF EXISTS idx_face_embeddings_frf_id
        ");
        DB::connection($this->connection)->statement("
            ALTER TABLE face_embeddings DROP COLUMN IF EXISTS file_registry_face_id
        ");
    }
};
