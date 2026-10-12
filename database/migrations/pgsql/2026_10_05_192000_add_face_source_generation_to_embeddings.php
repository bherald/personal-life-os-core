<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        Schema::connection($this->connection)->table('face_embeddings', function (Blueprint $table): void {
            $table->boolean('source_current')->default(true);
            $table->timestampTz('superseded_at')->nullable();
        });
    }

    public function down(): void
    {
        $historical = DB::connection($this->connection)->selectOne('SELECT 1 AS historical FROM face_embeddings WHERE source_current = FALSE OR superseded_at IS NOT NULL LIMIT 1');
        if ($historical) {
            throw new RuntimeException('Cannot roll back face generations while superseded face vectors exist; preserving lifecycle fields and vectors.');
        }

        Schema::connection($this->connection)->table('face_embeddings', fn (Blueprint $table) => $table->dropColumn(['source_current', 'superseded_at']));
    }
};
