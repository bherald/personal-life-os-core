<?php

namespace Tests\Unit\Services;

use App\Services\YouTubeTranscriptStorageService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for YouTubeTranscriptStorageService
 *
 * Tests transcript storage functionality including:
 * - Storing transcripts to database
 * - Retrieving transcripts
 * - Checking transcript existence
 * - Cleanup of old transcripts
 * - Storage statistics
 */
class YouTubeTranscriptStorageServiceTest extends TestCase
{
    private YouTubeTranscriptStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = Mockery::mock(YouTubeTranscriptStorageService::class)->makePartial();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // store() Tests
    // =========================================================================

    public function test_store_inserts_new_transcript(): void
    {
        $videoId = 'abc123def';
        $transcriptData = [
            'language' => 'en',
            'full_text' => 'This is a test transcript content.',
            'segments' => [['start' => 0, 'end' => 5, 'text' => 'Hello']],
            'method' => 'direct',
            'duration' => 300,
        ];

        // Check if exists
        DB::shouldReceive('selectOne')
            ->with(
                'SELECT id FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['abc123def', 'en']
            )
            ->once()
            ->andReturn(null);

        // Insert new record
        DB::shouldReceive('insert')
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'INSERT INTO youtube_transcripts')
                    && $params[0] === 'abc123def'
                    && $params[1] === 'en'
                    && $params[2] === 'This is a test transcript content.';
            })
            ->once()
            ->andReturn(true);

        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('lastInsertId')->andReturn('10');
        DB::shouldReceive('getPdo')->andReturn($pdo);

        $result = $this->service->store($videoId, $transcriptData);

        $this->assertEquals(10, $result);
    }

    public function test_store_updates_existing_transcript(): void
    {
        $videoId = 'existing123';
        $transcriptData = [
            'language' => 'en',
            'full_text' => 'Updated transcript content.',
            'method' => 'invidious',
        ];

        // Check if exists - returns existing
        DB::shouldReceive('selectOne')
            ->with(
                'SELECT id FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['existing123', 'en']
            )
            ->once()
            ->andReturn((object) ['id' => 5]);

        // Update existing record
        DB::shouldReceive('update')
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'UPDATE youtube_transcripts')
                    && str_contains($query, 'CASE WHEN BINARY content = BINARY ? THEN rag_indexed_at ELSE NULL END')
                    && $params[0] === 'Updated transcript content.'
                    && $params[1] === 'Updated transcript content.'
                    && $params[6] === 5;
            })
            ->once()
            ->andReturn(1);

        $result = $this->service->store($videoId, $transcriptData);

        $this->assertEquals(5, $result);
    }

    public function test_store_normalizes_source_method(): void
    {
        $videoId = 'method123';
        $transcriptData = [
            'full_text' => 'Content',
            'method' => 'timedtext', // Should normalize to 'direct_api'
        ];

        DB::shouldReceive('selectOne')->andReturn(null);

        DB::shouldReceive('insert')
            ->withArgs(function ($query, $params) {
                return $params[4] === 'direct_api'; // source_method normalized
            })
            ->once()
            ->andReturn(true);

        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('lastInsertId')->andReturn('1');
        DB::shouldReceive('getPdo')->andReturn($pdo);

        $this->service->store($videoId, $transcriptData);

        $this->assertTrue(true);
    }

    // =========================================================================
    // get() Tests
    // =========================================================================

    public function test_get_returns_transcript_record(): void
    {
        $videoId = 'get123';

        DB::shouldReceive('selectOne')
            ->with(
                'SELECT * FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['get123', 'en']
            )
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'video_id' => 'get123',
                'language' => 'en',
                'content' => 'Transcript content.',
                'timed_content' => null,
                'source_method' => 'direct_api',
            ]);

        $result = $this->service->get($videoId);

        $this->assertNotNull($result);
        $this->assertEquals('get123', $result->video_id);
        $this->assertEquals('Transcript content.', $result->content);
    }

    public function test_get_returns_null_when_not_found(): void
    {
        $videoId = 'notfound123';

        DB::shouldReceive('selectOne')
            ->with(
                'SELECT * FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['notfound123', 'en']
            )
            ->once()
            ->andReturn(null);

        $result = $this->service->get($videoId);

        $this->assertNull($result);
    }

    public function test_get_decodes_timed_content_json(): void
    {
        $videoId = 'timed123';
        $timedContent = [['start' => 0, 'end' => 5, 'text' => 'Hello']];

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'video_id' => 'timed123',
                'content' => 'Hello',
                'timed_content' => json_encode($timedContent),
            ]);

        $result = $this->service->get($videoId);

        $this->assertIsArray($result->timed_content);
        $this->assertEquals('Hello', $result->timed_content[0]['text']);
    }

    public function test_get_with_different_language(): void
    {
        $videoId = 'lang123';

        DB::shouldReceive('selectOne')
            ->with(
                'SELECT * FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['lang123', 'es']
            )
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'video_id' => 'lang123',
                'language' => 'es',
                'content' => 'Contenido en español',
                'timed_content' => null,
            ]);

        $result = $this->service->get($videoId, 'es');

        $this->assertEquals('es', $result->language);
    }

    // =========================================================================
    // exists() Tests
    // =========================================================================

    public function test_exists_returns_true_when_found(): void
    {
        $videoId = 'exists123';

        DB::shouldReceive('selectOne')
            ->with(
                'SELECT 1 FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['exists123', 'en']
            )
            ->once()
            ->andReturn((object) ['1' => 1]);

        $result = $this->service->exists($videoId);

        $this->assertTrue($result);
    }

    public function test_exists_returns_false_when_not_found(): void
    {
        $videoId = 'notexists123';

        DB::shouldReceive('selectOne')
            ->with(
                'SELECT 1 FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['notexists123', 'en']
            )
            ->once()
            ->andReturn(null);

        $result = $this->service->exists($videoId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // delete() Tests
    // =========================================================================

    public function test_delete_removes_specific_language(): void
    {
        $videoId = 'delete123';

        DB::shouldReceive('delete')
            ->with(
                'DELETE FROM youtube_transcripts WHERE video_id = ? AND language = ?',
                ['delete123', 'en']
            )
            ->once()
            ->andReturn(1);

        $result = $this->service->delete($videoId, 'en');

        $this->assertTrue($result);
    }

    public function test_delete_removes_all_languages(): void
    {
        $videoId = 'deleteall123';

        DB::shouldReceive('delete')
            ->with(
                'DELETE FROM youtube_transcripts WHERE video_id = ?',
                ['deleteall123']
            )
            ->once()
            ->andReturn(3);

        $result = $this->service->delete($videoId);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_when_not_found(): void
    {
        $videoId = 'deletenone123';

        DB::shouldReceive('delete')
            ->once()
            ->andReturn(0);

        $result = $this->service->delete($videoId, 'en');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getStats() Tests
    // =========================================================================

    public function test_get_stats_returns_statistics(): void
    {
        DB::shouldReceive('selectOne')
            ->with('SELECT COUNT(*) as count FROM youtube_transcripts')
            ->once()
            ->andReturn((object) ['count' => 1500]);

        DB::shouldReceive('select')
            ->withArgs(function ($query) {
                return str_contains($query, 'GROUP BY language');
            })
            ->once()
            ->andReturn([
                (object) ['language' => 'en', 'count' => 1200],
                (object) ['language' => 'es', 'count' => 200],
            ]);

        DB::shouldReceive('select')
            ->withArgs(function ($query) {
                return str_contains($query, 'GROUP BY source_method');
            })
            ->once()
            ->andReturn([
                (object) ['source_method' => 'direct_api', 'count' => 800],
                (object) ['source_method' => 'invidious', 'count' => 500],
            ]);

        DB::shouldReceive('selectOne')
            ->withArgs(function ($query) {
                return str_contains($query, 'AVG(word_count)');
            })
            ->once()
            ->andReturn((object) ['avg_words' => 5000, 'max_words' => 15000]);

        DB::shouldReceive('select')
            ->withArgs(function ($query) {
                return str_contains($query, 'DATE(fetched_at)');
            })
            ->once()
            ->andReturn([]);

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('by_language', $stats);
        $this->assertArrayHasKey('by_source', $stats);
        $this->assertEquals(1500, $stats['total']);
    }

    // =========================================================================
    // cleanupOld() Tests
    // =========================================================================

    public function test_cleanup_old_deletes_old_transcripts(): void
    {
        $daysOld = 365;

        DB::shouldReceive('delete')
            ->with(
                'DELETE FROM youtube_transcripts WHERE fetched_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$daysOld]
            )
            ->once()
            ->andReturn(50);

        $result = $this->service->cleanupOld($daysOld);

        $this->assertEquals(50, $result);
    }

    public function test_cleanup_old_uses_default_365_days(): void
    {
        DB::shouldReceive('delete')
            ->with(
                'DELETE FROM youtube_transcripts WHERE fetched_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [365]
            )
            ->once()
            ->andReturn(0);

        $result = $this->service->cleanupOld();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getAsTranscriptResult() Tests
    // =========================================================================

    public function test_get_as_transcript_result_returns_formatted_array(): void
    {
        $videoId = 'result123';

        // Mock get() internally
        $this->service->shouldReceive('get')
            ->with($videoId, 'en')
            ->once()
            ->andReturn((object) [
                'video_id' => 'result123',
                'language' => 'en',
                'content' => 'Transcript text',
                'timed_content' => [['start' => 0, 'text' => 'Hello']],
                'word_count' => 100,
                'duration_seconds' => 300,
                'source_method' => 'direct_api',
                'fetched_at' => '2026-02-04 10:00:00',
            ]);

        $result = $this->service->getAsTranscriptResult($videoId);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('result123', $result['video_id']);
        $this->assertEquals('Transcript text', $result['full_text']);
        $this->assertTrue($result['from_storage']);
    }

    public function test_get_as_transcript_result_returns_null_when_not_found(): void
    {
        $videoId = 'missing123';

        $this->service->shouldReceive('get')
            ->with($videoId, 'en')
            ->once()
            ->andReturn(null);

        $result = $this->service->getAsTranscriptResult($videoId);

        $this->assertNull($result);
    }

    // =========================================================================
    // search() Tests
    // =========================================================================

    public function test_search_finds_transcripts_by_content(): void
    {
        $query = 'specific phrase';

        DB::shouldReceive('select')
            ->withArgs(function ($sql, $params) use ($query) {
                return str_contains($sql, 'LIKE')
                    && $params[0] === '%'.$query.'%';
            })
            ->once()
            ->andReturn([
                (object) [
                    'video_id' => 'found1',
                    'language' => 'en',
                    'word_count' => 100,
                    'source_method' => 'direct_api',
                    'fetched_at' => '2026-02-04',
                    'content_preview' => '...specific phrase...',
                ],
            ]);

        $results = $this->service->search($query);

        $this->assertCount(1, $results);
        $this->assertEquals('found1', $results[0]['video_id']);
    }

    // =========================================================================
    // getMissing() Tests
    // =========================================================================

    public function test_get_missing_returns_video_ids_not_in_storage(): void
    {
        $videoIds = ['id1', 'id2', 'id3'];

        DB::shouldReceive('select')
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'SELECT video_id FROM youtube_transcripts')
                    && count($params) === 4; // 3 video IDs + language
            })
            ->once()
            ->andReturn([
                (object) ['video_id' => 'id1'],
            ]);

        $result = $this->service->getMissing($videoIds);

        $this->assertCount(2, $result);
        $this->assertContains('id2', $result);
        $this->assertContains('id3', $result);
        $this->assertNotContains('id1', $result);
    }

    public function test_get_missing_returns_empty_for_empty_input(): void
    {
        $result = $this->service->getMissing([]);

        $this->assertEmpty($result);
    }
}
