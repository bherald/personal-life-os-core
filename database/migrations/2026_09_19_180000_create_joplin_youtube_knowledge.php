<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mirror = DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'review_mirror')->first();
        if ($mirror) {
            $mirrorPolicy = json_decode($mirror->config_value, true, 512, JSON_THROW_ON_ERROR);
            $mirrorPolicy['allow_owned_prune'] = true;
            $mirrorPolicy['prune_backup_relative'] = 'operations/joplin-review-prune';
            DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'review_mirror')->update([
                'config_value' => json_encode($mirrorPolicy, JSON_UNESCAPED_SLASHES),
                'description' => 'Manual private review mirror: titles, admission limits, lock lease and explicit digest-verified owned pruning; no scheduler.',
                'updated_at' => now(),
            ]);
        }

        Schema::create('joplin_youtube_knowledge_items', function (Blueprint $table): void {
            $table->string('note_id', 32)->primary();
            $table->string('video_id', 11)->nullable()->index();
            $table->string('topic', 80)->index();
            $table->string('format_class', 32)->default('general')->index();
            $table->string('completeness_state', 32)->index();
            $table->string('review_state', 32)->default('needs_review')->index();
            $table->string('source_hash', 64);
            $table->unsignedInteger('transcript_words')->default(0);
            $table->json('issues');
            $table->json('provenance');
            $table->boolean('present')->default(true)->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('joplin_youtube_knowledge_notes', function (Blueprint $table): void {
            $table->string('note_key', 100)->primary();
            $table->string('note_id', 32)->unique();
            $table->string('parent_key', 100)->nullable();
            $table->string('title', 255);
            $table->string('remote_hash', 64)->nullable();
            $table->timestamps();
        });

        DB::table('system_configs')->insertOrIgnore([
            'section' => 'joplin',
            'config_key' => 'youtube_knowledge',
            'data_type' => 'json',
            'description' => 'Table-owned Joplin YouTube Knowledge layout, transcript review thresholds and content-format rules.',
            'config_value' => json_encode([
                'root_title' => 'YouTube Knowledge',
                'inbox_title' => 'Inbox',
                'minimum_transcript_words' => 100,
                'batch_size' => 25,
                'topics' => [
                    'Technology & PLOS' => ['ai', 'llm', 'software', 'coding', 'programming', 'automation', 'technology', 'privacy', 'security', 'network'],
                    'Health' => ['health', 'fitness', 'exercise', 'nutrition', 'sleep', 'wellness', 'medicine', 'medical'],
                    'Home & Preparedness' => ['diy', 'home', 'repair', 'garden', 'woodworking', 'tool', 'maintenance', 'generator', 'solar'],
                    'Survival & Prepping' => ['survival', 'prepping', 'preparedness', 'emergency', 'wilderness'],
                    'Money & Work' => ['business', 'finance', 'investing', 'budget', 'tax', 'career', 'job', 'work'],
                    'Vehicles & Travel' => ['car', 'truck', 'vehicle', 'engine', 'mechanic', 'travel', 'flight', 'hotel'],
                    'Food & Recipes' => ['food', 'cooking', 'recipe', 'baking', 'grill', 'meal', 'kitchen', 'ingredient'],
                    'Music & Entertainment' => ['music', 'song', 'album', 'concert', 'movie', 'film', 'podcast'],
                    'Personal & Family' => ['family', 'genealogy', 'ancestor', 'personal', 'children', 'parent'],
                    'Science & Society' => ['science', 'education', 'history', 'physics', 'biology', 'news', 'policy', 'government', 'law'],
                ],
                'review_topic' => 'Review Queue',
                'food_recipe_terms' => ['recipe', 'ingredients', 'tablespoon', 'teaspoon', 'cup', 'oven', 'bake'],
                'nonfood_formula_terms' => ['repellent', 'yellow jacket', 'wasp', 'fly spray', 'pest', 'weed killer', 'cleaner'],
                'edited_label' => 'Edited for clarity and concision',
                'preserve_original_metric_values' => true,
                'retain_original_transcript_after_rewrite' => false,
                'ingest_creator_supporting_links' => true,
                'ingest_user_comments' => false,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain operator review state and managed-note ownership on rollback.
    }
};
