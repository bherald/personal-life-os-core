<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('genealogy_face_match_queue', 'idx_face_queue_media_name_face')) {
            DB::statement('CREATE INDEX idx_face_queue_media_name_face ON genealogy_face_match_queue (media_id, face_name, file_registry_face_id)');
        }

        if ($this->indexExists('genealogy_face_match_queue', 'unique_media_face')) {
            DB::statement('ALTER TABLE genealogy_face_match_queue DROP INDEX unique_media_face');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('genealogy_face_match_queue', 'idx_face_queue_media_name_face')) {
            DB::statement('ALTER TABLE genealogy_face_match_queue DROP INDEX idx_face_queue_media_name_face');
        }

        if (! $this->indexExists('genealogy_face_match_queue', 'unique_media_face')) {
            DB::statement('CREATE UNIQUE INDEX unique_media_face ON genealogy_face_match_queue (media_id, face_name)');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
