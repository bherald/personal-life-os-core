<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE = 'Real News No Bullshit';

    private const NOTE = 'Operator manual center rating for local news_brief feed coverage. No MBFC/AllSides-derived rating was matched as of 2026-04-30; revisit if a third-party dataset adds this source.';

    public function up(): void
    {
        if (! Schema::hasTable('bias_ratings')) {
            return;
        }

        $this->ensureManualDataSource();
        $this->ensureNotesColumn();

        DB::table('bias_ratings')->updateOrInsert(
            ['news_source' => self::SOURCE],
            [
                'rating' => 'center',
                'rating_num' => 0,
                'data_source' => 'manual',
                'type' => 'News Media',
                'url' => 'https://www.realnewsnotbs.com',
                'screen_name' => 'realnewsnotbs.com',
                'confidence_level' => 'operator-manual',
                'is_polarizing_source' => 0,
                'notes' => self::NOTE,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        if (! Schema::hasTable('bias_rating_aliases')) {
            return;
        }

        foreach ($this->aliases() as $alias => $note) {
            DB::table('bias_rating_aliases')->updateOrInsert(
                ['alias' => $alias],
                [
                    'canonical_source' => self::SOURCE,
                    'active' => true,
                    'notes' => $note.' '.self::NOTE,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->clearBiasRatingCache();
    }

    public function down(): void
    {
        if (Schema::hasTable('bias_rating_aliases')) {
            DB::table('bias_rating_aliases')
                ->whereIn('alias', array_keys($this->aliases()))
                ->where('canonical_source', self::SOURCE)
                ->delete();
        }

        if (Schema::hasTable('bias_ratings')) {
            DB::table('bias_ratings')
                ->where('news_source', self::SOURCE)
                ->where('data_source', 'manual')
                ->delete();
        }
    }

    private function ensureManualDataSource(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE bias_ratings MODIFY data_source ENUM('allsides','mbfc','both','manual') NOT NULL DEFAULT 'allsides'"
        );
    }

    private function ensureNotesColumn(): void
    {
        if (Schema::hasColumn('bias_ratings', 'notes')) {
            return;
        }

        Schema::table('bias_ratings', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('screen_name');
        });
    }

    private function aliases(): array
    {
        return [
            'realnewsnotbs.com' => 'Canonical feed/site host.',
            'www.realnewsnotbs.com' => 'Canonical feed/site host with www prefix.',
            'real news no bullshit' => 'news_brief feed label without subtitle.',
            'real news no bullshit - unbiased news without agenda' => 'Full news_brief feed label.',
        ];
    }

    private function clearBiasRatingCache(): void
    {
        foreach ($this->cacheInputs() as [$sourceName, $feedUrl]) {
            Cache::forget('bias_rating:'.md5(strtolower($sourceName).($feedUrl ?? '')));
        }
    }

    private function cacheInputs(): array
    {
        return [
            [self::SOURCE, null],
            ['Real News No Bullshit - Unbiased news without agenda', 'https://www.realnewsnotbs.com/feed/'],
            ['realnewsnotbs.com', null],
            ['www.realnewsnotbs.com', null],
        ];
    }
};
