<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement('ALTER TABLE face_embeddings ADD COLUMN source_sha256 CHAR(64) NULL, ADD COLUMN face_region_convention VARCHAR(40) NULL');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('ALTER TABLE face_embeddings DROP COLUMN source_sha256, DROP COLUMN face_region_convention');
    }
};
