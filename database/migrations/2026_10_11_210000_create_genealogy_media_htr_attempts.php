<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genealogy_media_htr_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('tree_id');
            $table->char('source_sha256', 64);
            $table->char('source_revision_sha256', 64);
            $table->uuid('attempt_token')->unique('gm_htr_attempt_token_unique');
            $table->string('state', 32);
            $table->string('outcome_code', 48)->nullable();
            $table->timestamps(6);
            $table->unique(['media_id', 'source_revision_sha256'], 'gm_htr_media_revision_unique');
            $table->index(['media_id', 'state'], 'gm_htr_media_state_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('genealogy_media_htr_attempts')
            && DB::table('genealogy_media_htr_attempts')->exists()) {
            throw new RuntimeException('Retain genealogy HTR attempt custody during rollback.');
        }

        Schema::dropIfExists('genealogy_media_htr_attempts');
    }
};
