<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NOTES = 'Seeded from legacy BiasRatingService source normalization; operator-editable alias.';

    public function up(): void
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return;
        }

        foreach ($this->aliases() as $alias => $canonicalSource) {
            $exists = DB::table('bias_rating_aliases')
                ->where('alias', $alias)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('bias_rating_aliases')->insert([
                'alias' => $alias,
                'canonical_source' => $canonicalSource,
                'active' => true,
                'notes' => self::NOTES,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return;
        }

        DB::table('bias_rating_aliases')
            ->whereIn('alias', array_keys($this->aliases()))
            ->where('notes', self::NOTES)
            ->delete();
    }

    private function aliases(): array
    {
        return [
            'abcnews.go.com' => 'ABC News',
            'ap.org' => 'Associated Press',
            'apnews.com' => 'Associated Press',
            'bbc news' => 'BBC',
            'bbc.co.uk' => 'BBC',
            'bbc.com' => 'BBC',
            'bloomberg.com' => 'Bloomberg',
            'breitbart.com' => 'Breitbart News',
            'businessinsider.com' => 'Business Insider',
            'cbsnews.com' => 'CBS News',
            'cnn' => 'CNN',
            'cnn.com' => 'CNN',
            'economist.com' => 'The Economist',
            'forbes.com' => 'Forbes',
            'fox news' => 'Fox News',
            'foxnews.com' => 'Fox News',
            'huffingtonpost.com' => 'HuffPost',
            'huffpost.com' => 'HuffPost',
            'latimes.com' => 'Los Angeles Times',
            'msnbc.com' => 'MSNBC',
            'nbcnews.com' => 'NBC News',
            'newsweek.com' => 'Newsweek',
            'npr.org' => 'NPR',
            'nypost.com' => 'New York Post',
            'nyt' => 'The New York Times',
            'nytimes.com' => 'The New York Times',
            'pbs.org' => 'PBS',
            'politico.com' => 'Politico',
            'reuters.com' => 'Reuters',
            'slate.com' => 'Slate',
            'theatlantic.com' => 'The Atlantic',
            'theguardian.com' => 'The Guardian',
            'thehill.com' => 'The Hill',
            'time.com' => 'Time',
            'usatoday.com' => 'USA Today',
            'vox.com' => 'Vox',
            'washingtonpost.com' => 'Washington Post',
            'wsj.com' => 'Wall Street Journal',
        ];
    }
};
