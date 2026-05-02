<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return;
        }

        foreach ($this->aliases() as $alias => $values) {
            DB::table('bias_rating_aliases')->updateOrInsert(
                ['alias' => $alias],
                array_merge($values, [
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return;
        }

        DB::table('bias_rating_aliases')
            ->whereIn('alias', array_keys($this->aliases()))
            ->delete();
    }

    private function aliases(): array
    {
        return [
            'foxnews.com' => [
                'canonical_source' => 'Fox Online News',
                'notes' => 'Fox RSS feed host emitted by feeds.foxnews.com after source-host normalization.',
            ],
            'moxie.foxnews.com' => [
                'canonical_source' => 'Fox Online News',
                'notes' => 'Fox News Google Publisher RSS host used by U.S. and world feed sections.',
            ],
            'fox news - latest headlines' => [
                'canonical_source' => 'Fox Online News',
                'notes' => 'news_brief feed label for the general Fox News RSS feed.',
            ],
            'fox news - politics' => [
                'canonical_source' => 'Fox Online News',
                'notes' => 'news_brief feed label for the Fox News politics RSS feed.',
            ],
            'fox news - u.s. news' => [
                'canonical_source' => 'Fox Online News',
                'notes' => 'news_brief feed label for the Fox News U.S. RSS feed.',
            ],
            'fox news - world' => [
                'canonical_source' => 'Fox Online News',
                'notes' => 'news_brief feed label for the Fox News world RSS feed.',
            ],
        ];
    }
};
