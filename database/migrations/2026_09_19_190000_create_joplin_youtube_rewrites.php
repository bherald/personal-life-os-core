<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joplin_youtube_knowledge_rewrites', function (Blueprint $table): void {
            $table->string('video_id', 11)->primary();
            $table->string('canonical_note_id', 32)->unique();
            $table->json('source_note_ids');
            $table->json('source_hashes');
            $table->string('classification', 32)->index();
            $table->string('target_topic', 80)->index();
            $table->string('desired_hash', 64);
            $table->string('bundle_hash', 64);
            $table->string('backup_relative', 255);
            $table->string('status', 32)->default('verified')->index();
            $table->timestamps();
        });

        $row = DB::table('system_configs')
            ->where('section', 'joplin')
            ->where('config_key', 'youtube_knowledge')
            ->first();
        if (! $row) {
            return;
        }
        $policy = json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR);
        $policy['rewrite'] = [
            'enabled' => true,
            'max_items' => 500,
            'max_bundle_bytes' => 10 * 1024 * 1024,
            'max_body_bytes' => 128 * 1024,
            'batch_size' => 10,
            'backup_relative' => 'operations/joplin-youtube-rewrites',
            'allowed_classes' => ['food_recipe', 'nonfood_formula', 'other'],
            'food_topic' => 'Food & Recipes',
            'forbidden_headings' => ['Full Transcript', 'Transcript'],
        ];
        DB::table('system_configs')
            ->where('section', 'joplin')
            ->where('config_key', 'youtube_knowledge')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'description' => 'Table-owned Joplin YouTube Knowledge layout, audit, recipe rewrite, exact duplicate consolidation and content-format rules.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Retain rewrite ownership and private backup references on rollback.
    }
};
