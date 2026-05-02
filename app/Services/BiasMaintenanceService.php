<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Bias Maintenance Service
 *
 * Handles monthly maintenance tasks for bias-related data:
 * - Refresh the free MBFC-derived ratings from GitHub by default
 * - Allow optional operator-selected AllSides enrichment
 * - Update polarizing source flags
 * - AI-driven emotional word discovery
 *
 * Called from scheduled Maintenance jobs on the 1st of each month.
 */
class BiasMaintenanceService
{
    private const DEFAULT_REFRESH_SOURCE = 'free';

    private const VALID_REFRESH_SOURCES = ['free', 'mbfc', 'allsides', 'both'];

    /**
     * Known polarizing sources - these are sources that tend to generate
     * strong emotional reactions regardless of factual accuracy.
     * Based on MBFC "Questionable" category and AllSides extreme ratings.
     */
    private const POLARIZING_SOURCES = [
        // Left-leaning polarizing sources
        'Daily Kos',
        'Occupy Democrats',
        'Palmer Report',
        'Bipartisan Report',
        'The Other 98%',
        'Addicting Info',
        'Blue Nation Review',
        'Democratic Underground',
        'Raw Story',
        'Alternet',
        'Truthout',
        'Common Dreams',
        'Jacobin',
        'The Young Turks',
        'Mother Jones',
        'ThinkProgress',
        'Salon',
        'Vox',

        // Right-leaning polarizing sources
        'Breitbart',
        'Breitbart News',
        'InfoWars',
        'The Daily Wire',
        'The Blaze',
        'Gateway Pundit',
        'The Federalist',
        'Daily Caller',
        'Washington Examiner',
        'Newsmax',
        'One America News',
        'OAN',
        'Zero Hedge',
        'ZeroHedge',
        'National Review',
        'The Daily Signal',
        'Western Journal',
        'Epoch Times',
        'The Epoch Times',
        'PJ Media',
        'Townhall',
        'RedState',
        'American Thinker',
        'WND',
        'WorldNetDaily',
        'Conservative Tribune',
        'Right Wing News',

        // Conspiracy/Pseudo-science (both sides)
        'Natural News',
        'Mercola',
        'Collective Evolution',
        'Before Its News',
        'Your News Wire',
        'News Punch',
        'True Pundit',
        'The Liberty Daily',
    ];

    /**
     * Additional emotional/sensational words to seed the database
     * Organized by sentiment category
     */
    private const EMOTIONAL_WORDS_SEED = [
        'sensational' => [
            // Headlines/clickbait words
            ['word' => 'shocking', 'intensity' => 3],
            ['word' => 'outrage', 'intensity' => 3],
            ['word' => 'outraged', 'intensity' => 3],
            ['word' => 'destroy', 'intensity' => 3],
            ['word' => 'destroyed', 'intensity' => 3],
            ['word' => 'destroys', 'intensity' => 3],
            ['word' => 'slam', 'intensity' => 2],
            ['word' => 'slams', 'intensity' => 2],
            ['word' => 'slammed', 'intensity' => 2],
            ['word' => 'blast', 'intensity' => 2],
            ['word' => 'blasts', 'intensity' => 2],
            ['word' => 'epic', 'intensity' => 2],
            ['word' => 'bombshell', 'intensity' => 3],
            ['word' => 'explosive', 'intensity' => 3],
            ['word' => 'stunning', 'intensity' => 2],
            ['word' => 'unbelievable', 'intensity' => 3],
            ['word' => 'incredible', 'intensity' => 2],
            ['word' => 'insane', 'intensity' => 3],
            ['word' => 'crazy', 'intensity' => 2],
            ['word' => 'wild', 'intensity' => 2],
            ['word' => 'brutal', 'intensity' => 3],
            ['word' => 'savage', 'intensity' => 3],
            ['word' => 'devastating', 'intensity' => 3],
            ['word' => 'catastrophic', 'intensity' => 3],
            ['word' => 'horrifying', 'intensity' => 3],
            ['word' => 'terrifying', 'intensity' => 3],
            ['word' => 'nightmare', 'intensity' => 3],
            ['word' => 'chaos', 'intensity' => 2],
            ['word' => 'crisis', 'intensity' => 2],
            ['word' => 'emergency', 'intensity' => 2],
            ['word' => 'urgent', 'intensity' => 2],
            ['word' => 'breaking', 'intensity' => 1],
            ['word' => 'exclusive', 'intensity' => 1],
            ['word' => 'exposed', 'intensity' => 2],
            ['word' => 'busted', 'intensity' => 2],
            ['word' => 'caught', 'intensity' => 2],
            ['word' => 'revealed', 'intensity' => 1],
            ['word' => 'secret', 'intensity' => 2],
            ['word' => 'leaked', 'intensity' => 2],
            ['word' => 'scandal', 'intensity' => 3],
            ['word' => 'corrupt', 'intensity' => 3],
            ['word' => 'corruption', 'intensity' => 3],
            ['word' => 'crooked', 'intensity' => 3],
            ['word' => 'rigged', 'intensity' => 3],
            ['word' => 'hoax', 'intensity' => 3],
            ['word' => 'fake', 'intensity' => 2],
            ['word' => 'fraud', 'intensity' => 3],
            ['word' => 'scam', 'intensity' => 3],
            ['word' => 'lie', 'intensity' => 2],
            ['word' => 'lies', 'intensity' => 2],
            ['word' => 'liar', 'intensity' => 3],
            ['word' => 'hypocrite', 'intensity' => 2],
            ['word' => 'hypocrisy', 'intensity' => 2],
            ['word' => 'pathetic', 'intensity' => 2],
            ['word' => 'disgrace', 'intensity' => 3],
            ['word' => 'disgusting', 'intensity' => 3],
            ['word' => 'vile', 'intensity' => 3],
            ['word' => 'evil', 'intensity' => 3],
            ['word' => 'monster', 'intensity' => 3],
            ['word' => 'villain', 'intensity' => 2],
            ['word' => 'enemy', 'intensity' => 2],
            ['word' => 'threat', 'intensity' => 2],
            ['word' => 'danger', 'intensity' => 2],
            ['word' => 'dangerous', 'intensity' => 2],
            ['word' => 'deadly', 'intensity' => 3],
            ['word' => 'lethal', 'intensity' => 3],
            ['word' => 'toxic', 'intensity' => 2],
            ['word' => 'radical', 'intensity' => 2],
            ['word' => 'extreme', 'intensity' => 2],
            ['word' => 'extremist', 'intensity' => 3],
            ['word' => 'fanatic', 'intensity' => 3],
        ],
        'negative' => [
            ['word' => 'fail', 'intensity' => 2],
            ['word' => 'fails', 'intensity' => 2],
            ['word' => 'failed', 'intensity' => 2],
            ['word' => 'failure', 'intensity' => 2],
            ['word' => 'collapse', 'intensity' => 3],
            ['word' => 'crash', 'intensity' => 2],
            ['word' => 'plunge', 'intensity' => 2],
            ['word' => 'plummet', 'intensity' => 2],
            ['word' => 'tank', 'intensity' => 2],
            ['word' => 'tanking', 'intensity' => 2],
            ['word' => 'disaster', 'intensity' => 3],
            ['word' => 'fiasco', 'intensity' => 3],
            ['word' => 'debacle', 'intensity' => 3],
            ['word' => 'meltdown', 'intensity' => 3],
            ['word' => 'trainwreck', 'intensity' => 3],
            ['word' => 'dumpster fire', 'intensity' => 3],
            ['word' => 'bloodbath', 'intensity' => 3],
            ['word' => 'massacre', 'intensity' => 3],
            ['word' => 'slaughter', 'intensity' => 3],
            ['word' => 'annihilate', 'intensity' => 3],
            ['word' => 'obliterate', 'intensity' => 3],
            ['word' => 'eviscerate', 'intensity' => 3],
            ['word' => 'condemn', 'intensity' => 2],
            ['word' => 'denounce', 'intensity' => 2],
            ['word' => 'rebuke', 'intensity' => 2],
            ['word' => 'criticize', 'intensity' => 1],
            ['word' => 'attack', 'intensity' => 2],
            ['word' => 'attacks', 'intensity' => 2],
            ['word' => 'assault', 'intensity' => 3],
        ],
        'positive' => [
            ['word' => 'hero', 'intensity' => 2],
            ['word' => 'heroic', 'intensity' => 2],
            ['word' => 'triumph', 'intensity' => 2],
            ['word' => 'victory', 'intensity' => 2],
            ['word' => 'win', 'intensity' => 1],
            ['word' => 'wins', 'intensity' => 1],
            ['word' => 'perfect', 'intensity' => 2],
            ['word' => 'brilliant', 'intensity' => 2],
            ['word' => 'genius', 'intensity' => 2],
            ['word' => 'amazing', 'intensity' => 2],
            ['word' => 'awesome', 'intensity' => 2],
            ['word' => 'fantastic', 'intensity' => 2],
            ['word' => 'wonderful', 'intensity' => 2],
            ['word' => 'miracle', 'intensity' => 3],
            ['word' => 'historic', 'intensity' => 2],
            ['word' => 'revolutionary', 'intensity' => 2],
            ['word' => 'groundbreaking', 'intensity' => 2],
            ['word' => 'game-changer', 'intensity' => 2],
            ['word' => 'unprecedented', 'intensity' => 2],
        ],
    ];

    /**
     * Run all monthly maintenance tasks
     */
    public function runMonthlyMaintenance(string $source = self::DEFAULT_REFRESH_SOURCE): array
    {
        $refreshSource = $this->normalizeRefreshSource($source);

        $results = [
            'bias_refresh' => null,
            'polarizing_sources_updated' => 0,
            'emotional_words_added' => 0,
            'refresh_source' => $refreshSource,
            'timestamp' => now()->toIso8601String(),
        ];

        // 1. Refresh bias ratings from the selected source.
        $results['bias_refresh'] = $this->refreshBiasRatings($refreshSource);

        // 2. Update polarizing source flags
        $results['polarizing_sources_updated'] = $this->updatePolarizingSources();

        // 3. Ensure emotional words are seeded
        $results['emotional_words_added'] = $this->seedEmotionalWords();

        return $results;
    }

    /**
     * Refresh bias ratings from selected GitHub sources.
     *
     * The default free path uses the Apache-2.0 Idiap MBFC-derived dataset.
     * AllSides remains an explicit operator-selected enrichment source.
     */
    public function refreshBiasRatings(string $source = self::DEFAULT_REFRESH_SOURCE): array
    {
        $refreshSource = $this->normalizeRefreshSource($source);
        $runAllSides = in_array($refreshSource, ['allsides', 'both'], true);
        $runMbfc = in_array($refreshSource, ['free', 'mbfc', 'both'], true);

        $results = [
            'source' => $refreshSource,
            'allsides' => ['status' => 'skipped'],
            'mbfc' => ['status' => 'skipped'],
        ];

        if ($runAllSides) {
            try {
                Log::info('BiasMaintenanceService: Refreshing AllSides ratings...');
                $exitCode = Artisan::call('bias:import');
                Artisan::output();

                $results['allsides'] = [
                    'status' => $exitCode === 0 ? 'success' : 'failed',
                    'exit_code' => $exitCode,
                ];

                Log::info('BiasMaintenanceService: AllSides import complete', ['exit_code' => $exitCode]);
            } catch (\Exception $e) {
                $results['allsides'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                Log::warning('BiasMaintenanceService: AllSides import failed', ['error' => $e->getMessage()]);
            }
        }

        if ($runMbfc) {
            try {
                Log::info('BiasMaintenanceService: Refreshing MBFC ratings...');
                $exitCode = Artisan::call('mbfc:import', ['--merge' => true]);
                Artisan::output();

                $results['mbfc'] = [
                    'status' => $exitCode === 0 ? 'success' : 'failed',
                    'exit_code' => $exitCode,
                ];

                Log::info('BiasMaintenanceService: MBFC import complete', ['exit_code' => $exitCode]);
            } catch (\Exception $e) {
                $results['mbfc'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                Log::warning('BiasMaintenanceService: MBFC import failed', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function normalizeRefreshSource(string $source): string
    {
        $normalized = strtolower(trim($source));

        if (! in_array($normalized, self::VALID_REFRESH_SOURCES, true)) {
            throw new InvalidArgumentException(
                'Invalid bias refresh source. Use one of: free, mbfc, allsides, both.'
            );
        }

        return $normalized;
    }

    /**
     * Mainstream sources that should NEVER be flagged as polarizing
     * even if they have extreme ratings in the database
     */
    private const MAINSTREAM_SOURCES = [
        'ABC News',
        'CBS News',
        'NBC News',
        'CNN',
        'Fox News',
        'MSNBC',
        'NPR',
        'PBS',
        'BBC',
        'Associated Press',
        'Reuters',
        'The New York Times',
        'Washington Post',
        'Wall Street Journal',
        'USA Today',
        'Los Angeles Times',
        'Chicago Tribune',
        'The Guardian',
        'The Atlantic',
        'Time',
        'Newsweek',
        'Bloomberg',
        'Forbes',
        'The Economist',
    ];

    /**
     * Update is_polarizing_source flag for known polarizing sources
     */
    public function updatePolarizingSources(): int
    {
        $updated = DB::transaction(function (): int {
            $updated = 0;

            // Reset all to non-polarizing first
            DB::update('UPDATE bias_ratings SET is_polarizing_source = 0');

            foreach (self::POLARIZING_SOURCES as $sourceName) {
                // Match by news_source name (case-insensitive partial match)
                $affected = DB::update('UPDATE bias_ratings SET is_polarizing_source = 1 WHERE news_source LIKE ?', ["%{$sourceName}%"]);
                $updated += $affected;
            }

            // Also flag extreme bias sources (rating_num = -2 or +2) with LOW credibility score
            $affected = DB::update('UPDATE bias_ratings SET is_polarizing_source = 1 WHERE rating_num IN (-2, 2) AND mbfc_credibility_score < 40 AND mbfc_credibility_score IS NOT NULL');
            $updated += $affected;

            // Ensure mainstream sources are NEVER flagged as polarizing
            foreach (self::MAINSTREAM_SOURCES as $source) {
                DB::update('UPDATE bias_ratings SET is_polarizing_source = 0 WHERE news_source LIKE ?', ["%{$source}%"]);
            }

            return $updated;
        });

        Log::info('BiasMaintenanceService: Updated polarizing sources', ['count' => $updated]);

        return $updated;
    }

    /**
     * Seed emotional language words if not already present
     */
    public function seedEmotionalWords(): int
    {
        $added = 0;

        foreach (self::EMOTIONAL_WORDS_SEED as $sentiment => $words) {
            foreach ($words as $wordData) {
                // Check if word already exists
                $exists = DB::selectOne('SELECT 1 FROM emotional_language_words WHERE word = ?', [$wordData['word']]);

                if (! $exists) {
                    DB::insert('INSERT INTO emotional_language_words (word, sentiment, intensity, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [
                        $wordData['word'], $sentiment, $wordData['intensity'], true, now(), now(),
                    ]);
                    $added++;
                }
            }
        }

        Log::info('BiasMaintenanceService: Seeded emotional words', ['added' => $added]);

        return $added;
    }

    /**
     * Discover new emotional words from recent article processing
     * This analyzes headlines that scored high on emotional language
     * and extracts new candidate words for human review.
     *
     * Called by AI during processing or as part of maintenance.
     *
     * @param  array  $candidates  Array of ['word' => string, 'sentiment' => string, 'context' => string]
     * @return int Number of new words added
     */
    public function addDiscoveredEmotionalWords(array $candidates): int
    {
        $added = 0;

        foreach ($candidates as $candidate) {
            $word = strtolower(trim($candidate['word'] ?? ''));
            $sentiment = $candidate['sentiment'] ?? 'sensational';
            $intensity = $candidate['intensity'] ?? 2;

            if (strlen($word) < 3 || strlen($word) > 30) {
                continue; // Skip invalid words
            }

            // Check if word already exists
            $exists = DB::selectOne('SELECT 1 FROM emotional_language_words WHERE word = ?', [$word]);

            if (! $exists) {
                DB::insert('INSERT INTO emotional_language_words (word, sentiment, intensity, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [
                    $word, $sentiment, $intensity, true, now(), now(),
                ]);
                $added++;

                Log::info('BiasMaintenanceService: Added discovered emotional word', [
                    'word' => $word,
                    'sentiment' => $sentiment,
                    'context' => $candidate['context'] ?? null,
                ]);
            }
        }

        return $added;
    }

    /**
     * Get current statistics for bias data
     */
    public function getStatistics(): array
    {
        $bySource = [];
        $rows = DB::select('SELECT data_source, COUNT(*) as count FROM bias_ratings GROUP BY data_source');
        foreach ($rows as $row) {
            $bySource[$row->data_source] = $row->count;
        }

        $polarizingResult = DB::selectOne('SELECT COUNT(*) as count FROM bias_ratings WHERE is_polarizing_source = 1');
        $polarizingCount = $polarizingResult->count ?? 0;

        $emotionalResult = DB::selectOne('SELECT COUNT(*) as count FROM emotional_language_words WHERE active = 1');
        $emotionalWordCount = $emotionalResult->count ?? 0;

        $polarizingTopicResult = DB::selectOne('SELECT COUNT(*) as count FROM polarizing_topics WHERE active = 1');
        $polarizingTopicCount = $polarizingTopicResult->count ?? 0;

        $lastRefreshResult = DB::selectOne('SELECT MAX(updated_at) as max_updated FROM bias_ratings');
        $aliasStats = $this->getAliasStatistics();

        return [
            'bias_ratings' => [
                'total' => array_sum($bySource),
                'by_source' => $bySource,
                'polarizing_sources' => $polarizingCount,
            ],
            'source_aliases' => $aliasStats,
            'emotional_words' => $emotionalWordCount,
            'polarizing_topics' => $polarizingTopicCount,
            'last_refresh' => $lastRefreshResult->max_updated ?? null,
        ];
    }

    private function getAliasStatistics(): array
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'orphaned' => 0,
            ];
        }

        $row = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN bra.active = 1 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN bra.active = 0 THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN br.id IS NULL THEN 1 ELSE 0 END) AS orphaned
             FROM bias_rating_aliases bra
             LEFT JOIN bias_ratings br ON br.news_source = bra.canonical_source'
        );

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
            'orphaned' => (int) ($row->orphaned ?? 0),
        ];
    }
}
