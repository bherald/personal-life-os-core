<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genealogy_face_match_queue', function (Blueprint $table): void {
            $table->char('evidence_generation_key', 64)->nullable();
            $table->unique('evidence_generation_key', 'genealogy_face_match_queue_generation_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('genealogy_face_match_queue')->whereNotNull('evidence_generation_key')->exists()) {
            throw new RuntimeException('Face queue generation keys contain retained custody history; migration rollback is refused.');
        }

        Schema::table('genealogy_face_match_queue', function (Blueprint $table): void {
            $table->dropUnique('genealogy_face_match_queue_generation_unique');
            $table->dropColumn('evidence_generation_key');
        });
    }
};
