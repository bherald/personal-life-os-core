<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Transcript Storage Service
 *
 * Provides persistent MySQL storage for YouTube transcripts.
 * Uses raw SQL per project standards (no Eloquent).
 */
class YouTubeTranscriptStorageService
{
    /**
     * Store a transcript in the database.
     *
     * @param string $videoId YouTube video ID
     * @param array $transcriptData Transcript data from YouTubeTranscriptService
     * @return int Inserted/updated row ID
     */
    public function store(string $videoId, array $transcriptData): int
    {
        $language = $transcriptData['language'] ?? 'en';
        $content = $transcriptData['full_text'] ?? $transcriptData['transcript'] ?? null;
        $timedContent = $transcriptData['segments'] ?? $transcriptData['timed_text'] ?? null;
        $sourceMethod = $this->normalizeSourceMethod($transcriptData['method'] ?? $transcriptData['source'] ?? 'unknown');
        $durationSeconds = $transcriptData['duration'] ?? $transcriptData['duration_seconds'] ?? null;
        $wordCount = $transcriptData['word_count'] ?? ($content ? str_word_count($content) : null);

        // Check if exists
        $existing = DB::selectOne(
            'SELECT id FROM youtube_transcripts WHERE video_id = ? AND language = ?',
            [$videoId, $language]
        );

        if ($existing) {
            // Update existing record
            DB::update(
                'UPDATE youtube_transcripts
                 SET content = ?, timed_content = ?, source_method = ?,
                     duration_seconds = ?, word_count = ?, fetched_at = NOW()
                 WHERE id = ?',
                [
                    $content,
                    $timedContent ? json_encode($timedContent) : null,
                    $sourceMethod,
                    $durationSeconds,
                    $wordCount,
                    $existing->id
                ]
            );

            Log::info('YouTubeTranscriptStorageService: Updated transcript', [
                'video_id' => $videoId,
                'language' => $language,
                'word_count' => $wordCount
            ]);

            return (int) $existing->id;
        }

        // Insert new record
        DB::insert(
            'INSERT INTO youtube_transcripts
             (video_id, language, content, timed_content, source_method, duration_seconds, word_count, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $videoId,
                $language,
                $content,
                $timedContent ? json_encode($timedContent) : null,
                $sourceMethod,
                $durationSeconds,
                $wordCount
            ]
        );

        $id = (int) DB::getPdo()->lastInsertId();

        Log::info('YouTubeTranscriptStorageService: Stored new transcript', [
            'video_id' => $videoId,
            'language' => $language,
            'word_count' => $wordCount,
            'id' => $id
        ]);

        return $id;
    }

    /**
     * Get a stored transcript.
     *
     * @param string $videoId YouTube video ID
     * @param string $language Language code
     * @return object|null Transcript record or null
     */
    public function get(string $videoId, string $language = 'en'): ?object
    {
        $record = DB::selectOne(
            'SELECT * FROM youtube_transcripts WHERE video_id = ? AND language = ?',
            [$videoId, $language]
        );

        if ($record && $record->timed_content) {
            $record->timed_content = json_decode($record->timed_content, true);
        }

        return $record;
    }

    /**
     * Check if a transcript exists in storage.
     *
     * @param string $videoId YouTube video ID
     * @param string $language Language code
     * @return bool
     */
    public function exists(string $videoId, string $language = 'en'): bool
    {
        $result = DB::selectOne(
            'SELECT 1 FROM youtube_transcripts WHERE video_id = ? AND language = ?',
            [$videoId, $language]
        );

        return $result !== null;
    }

    /**
     * Delete transcript(s) for a video.
     *
     * @param string $videoId YouTube video ID
     * @param string|null $language Specific language, or null for all languages
     * @return bool
     */
    public function delete(string $videoId, ?string $language = null): bool
    {
        if ($language !== null) {
            $deleted = DB::delete(
                'DELETE FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                [$videoId, $language]
            );
        } else {
            $deleted = DB::delete(
                'DELETE FROM youtube_transcripts WHERE video_id = ?',
                [$videoId]
            );
        }

        Log::info('YouTubeTranscriptStorageService: Deleted transcript(s)', [
            'video_id' => $videoId,
            'language' => $language,
            'deleted_count' => $deleted
        ]);

        return $deleted > 0;
    }

    /**
     * Get storage statistics.
     *
     * @return array Stats including total, by language, by source
     */
    public function getStats(): array
    {
        $total = DB::selectOne('SELECT COUNT(*) as count FROM youtube_transcripts');

        $byLanguage = DB::select(
            'SELECT language, COUNT(*) as count
             FROM youtube_transcripts
             GROUP BY language
             ORDER BY count DESC'
        );

        $bySource = DB::select(
            'SELECT source_method, COUNT(*) as count
             FROM youtube_transcripts
             GROUP BY source_method
             ORDER BY count DESC'
        );

        $avgWordCount = DB::selectOne(
            'SELECT AVG(word_count) as avg_words, MAX(word_count) as max_words
             FROM youtube_transcripts
             WHERE word_count IS NOT NULL'
        );

        $recentActivity = DB::select(
            'SELECT DATE(fetched_at) as date, COUNT(*) as count
             FROM youtube_transcripts
             WHERE fetched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(fetched_at)
             ORDER BY date DESC
             LIMIT 30'
        );

        return [
            'total' => (int) $total->count,
            'by_language' => array_map(fn($r) => ['language' => $r->language, 'count' => (int) $r->count], $byLanguage),
            'by_source' => array_map(fn($r) => ['source' => $r->source_method, 'count' => (int) $r->count], $bySource),
            'avg_word_count' => $avgWordCount ? (int) $avgWordCount->avg_words : 0,
            'max_word_count' => $avgWordCount ? (int) $avgWordCount->max_words : 0,
            'recent_activity' => array_map(fn($r) => ['date' => $r->date, 'count' => (int) $r->count], $recentActivity),
        ];
    }

    /**
     * Clean up old transcripts.
     *
     * @param int $daysOld Delete transcripts older than this many days
     * @return int Number of deleted records
     */
    public function cleanupOld(int $daysOld = 365): int
    {
        $deleted = DB::delete(
            'DELETE FROM youtube_transcripts WHERE fetched_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$daysOld]
        );

        Log::info('YouTubeTranscriptStorageService: Cleaned up old transcripts', [
            'days_old' => $daysOld,
            'deleted_count' => $deleted
        ]);

        return $deleted;
    }

    /**
     * Get transcript as array format compatible with YouTubeTranscriptService.
     *
     * @param string $videoId YouTube video ID
     * @param string $language Language code
     * @return array|null Transcript data or null
     */
    public function getAsTranscriptResult(string $videoId, string $language = 'en'): ?array
    {
        $record = $this->get($videoId, $language);

        if (!$record) {
            return null;
        }

        return [
            'success' => true,
            'video_id' => $record->video_id,
            'language' => $record->language,
            'full_text' => $record->content,
            'transcript' => $record->content,
            'segments' => $record->timed_content,
            'timed_text' => $record->timed_content,
            'word_count' => (int) $record->word_count,
            'duration' => (int) $record->duration_seconds,
            'duration_seconds' => (int) $record->duration_seconds,
            'method' => $record->source_method,
            'caption_type' => 'unknown',
            'source' => 'storage',
            'fetched_at' => $record->fetched_at,
            'from_storage' => true,
        ];
    }

    /**
     * Search transcripts by content (uses LIKE since FULLTEXT not available).
     *
     * @param string $query Search query
     * @param int $limit Max results
     * @return array Matching transcripts
     */
    public function search(string $query, int $limit = 20): array
    {
        // Use LIKE for basic search (FULLTEXT requires separate index)
        $results = DB::select(
            "SELECT video_id, language, word_count, source_method, fetched_at,
                    SUBSTRING(content, 1, 500) as content_preview
             FROM youtube_transcripts
             WHERE content LIKE ?
             ORDER BY fetched_at DESC
             LIMIT ?",
            ['%' . $query . '%', $limit]
        );

        return array_map(fn($r) => (array) $r, $results);
    }

    /**
     * Normalize source method name.
     *
     * @param string $method Raw method name
     * @return string Normalized method
     */
    private function normalizeSourceMethod(string $method): string
    {
        $map = [
            'timedtext' => 'direct_api',
            'direct' => 'direct_api',
            'youtube_api' => 'direct_api',
            'invidious' => 'invidious',
            'piped' => 'piped',
            'php_library' => 'library',
            'library' => 'library',
            'yt-dlp' => 'yt-dlp',
            'ytdlp' => 'yt-dlp',
        ];

        $normalized = strtolower(trim($method));
        return $map[$normalized] ?? $normalized;
    }

    /**
     * Get videos missing transcripts from a list.
     *
     * @param array $videoIds List of video IDs to check
     * @param string $language Language code
     * @return array Video IDs not in storage
     */
    public function getMissing(array $videoIds, string $language = 'en'): array
    {
        if (empty($videoIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
        $params = array_merge($videoIds, [$language]);

        $existing = DB::select(
            "SELECT video_id FROM youtube_transcripts
             WHERE video_id IN ({$placeholders}) AND language = ?",
            $params
        );

        $existingIds = array_map(fn($r) => $r->video_id, $existing);

        return array_values(array_diff($videoIds, $existingIds));
    }
}
