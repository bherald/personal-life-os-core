<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joplin_review_mirrors', function (Blueprint $t) {
            $t->string('mirror_key', 80)->primary();
            $t->string('parent_id', 32)->nullable();
            $t->string('notebook_id', 32)->nullable();
            $t->string('manifest_hash', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('joplin_review_items', function (Blueprint $t) {
            $t->id();
            $t->string('mirror_key', 80);
            $t->string('source_key', 64);
            $t->text('source_path');
            $t->string('note_id', 32)->unique();
            $t->string('source_hash', 64)->nullable();
            $t->string('remote_hash', 64)->nullable();
            $t->string('pending_hash', 64)->nullable();
            $t->string('resource_id', 32)->nullable();
            $t->timestamps();
            $t->unique(['mirror_key', 'source_key']);
        });
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'joplin', 'config_key' => 'review_mirror', 'data_type' => 'json',
            'description' => 'Manual private review mirror: titles, admission limits, lock lease and explicit digest-verified owned pruning; no scheduler.',
            'config_value' => json_encode([
                'mirror_key' => 'plos-review', 'parent_title' => 'PLOS', 'notebook_title' => 'Review Mirror',
                'max_files' => 1000, 'max_file_bytes' => 8000000, 'max_bundle_bytes' => 64000000,
                'lock_seconds' => 1800, 'allowed_extensions' => ['html', 'json', 'png', 'css'],
                'allow_owned_prune' => true, 'prune_backup_relative' => 'operations/joplin-review-prune',
            ]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain mapping/ownership and operator settings; rollback must never orphan or delete personal notes.
    }
};
