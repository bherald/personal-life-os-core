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
        // No historical checkpoint backfill: installed weights do not prove old vectors.
        Schema::connection($this->connection)->table('face_embeddings', function (Blueprint $table): void {
            $table->json('generation_provenance')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->table('face_embeddings')->whereNotNull('generation_provenance')->exists()) {
            throw new RuntimeException('Cannot discard exact vector checkpoint provenance; preserving historical evidence.');
        }
        Schema::connection($this->connection)->table('face_embeddings', fn (Blueprint $table) => $table->dropColumn('generation_provenance'));
    }
};
